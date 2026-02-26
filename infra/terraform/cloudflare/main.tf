terraform {
  required_providers {
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.0"
    }
  }
}

provider "cloudflare" {
  api_token = var.cloudflare_api_token
}

# ---------------------------------------------------------------------------
# Workers Script
# ---------------------------------------------------------------------------
resource "cloudflare_workers_script" "cache_worker" {
  account_id = var.cloudflare_account_id
  name       = "ai-wp-dynamic-cache-worker"
  content    = file("${path.module}/../../../workers/dist/index.js")

  kv_namespace_binding {
    name         = "CACHE_TAGS"
    namespace_id = cloudflare_kv_namespace.cache_tags.id
  }

  r2_bucket_binding {
    name        = "CACHE_BUCKET"
    bucket_name = cloudflare_r2_bucket.cache_bucket.name
  }
}

# ---------------------------------------------------------------------------
# KV Namespace for cache tag tracking
# ---------------------------------------------------------------------------
resource "cloudflare_kv_namespace" "cache_tags" {
  account_id = var.cloudflare_account_id
  title      = "ai-wp-cache-tags"
}

# ---------------------------------------------------------------------------
# R2 Bucket for cached HTML pages
# ---------------------------------------------------------------------------
resource "cloudflare_r2_bucket" "cache_bucket" {
  account_id = var.cloudflare_account_id
  name       = "ai-wp-cache-${var.cloudflare_account_id}"
  location   = "WNAM"
}

# ---------------------------------------------------------------------------
# Worker Route – handle all requests on the zone
# ---------------------------------------------------------------------------
resource "cloudflare_worker_route" "cache_route" {
  zone_id     = var.cloudflare_zone_id
  pattern     = "*${var.zone_hostname}/*"
  script_name = cloudflare_workers_script.cache_worker.name
}

# ---------------------------------------------------------------------------
# Cache Rules Ruleset
# ---------------------------------------------------------------------------
resource "cloudflare_ruleset" "cache_rules" {
  zone_id     = var.cloudflare_zone_id
  name        = "AI WP Dynamic Cache Rules"
  description = "Managed cache rules for WordPress"
  kind        = "zone"
  phase       = "http_request_cache_settings"

  rules {
    description = "Cache static assets for 1 year"
    expression  = "(http.request.uri.path matches \"\\.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot|webp|avif)$\")"
    action      = "set_cache_settings"
    action_parameters {
      cache = true
      edge_ttl {
        mode    = "override_origin"
        default = 31536000
      }
      browser_ttl {
        mode    = "override_origin"
        default = 31536000
      }
    }
  }

  rules {
    description = "Bypass cache for wp-admin and auth endpoints"
    expression  = "(http.request.uri.path contains \"/wp-admin\") or (http.request.uri.path contains \"/wp-login.php\")"
    action      = "set_cache_settings"
    action_parameters {
      cache = false
    }
  }

  rules {
    description = "Bypass cache for cart and checkout (WooCommerce)"
    expression  = "(http.request.uri.path contains \"/cart\") or (http.request.uri.path contains \"/checkout\")"
    action      = "set_cache_settings"
    action_parameters {
      cache = false
    }
  }

  rules {
    description = "Cache HTML pages with variable TTL"
    expression  = "(http.request.method eq \"GET\") and (not http.request.uri.path contains \"/wp-admin\") and (not any(http.cookies.names[*] contains \"wordpress_logged_in\"))"
    action      = "set_cache_settings"
    action_parameters {
      cache = true
      edge_ttl {
        mode    = "override_origin"
        default = 3600
      }
      browser_ttl {
        mode    = "override_origin"
        default = 0
      }
      serve_stale {
        disable_stale_while_updating = false
      }
    }
  }
}
