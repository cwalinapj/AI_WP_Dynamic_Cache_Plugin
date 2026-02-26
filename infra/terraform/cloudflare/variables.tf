variable "cloudflare_account_id" {
  description = "Cloudflare Account ID"
  type        = string
  sensitive   = true
}

variable "cloudflare_zone_id" {
  description = "Cloudflare Zone ID for the target domain"
  type        = string
  sensitive   = true
}

variable "cloudflare_api_token" {
  description = "Cloudflare API token with Workers, KV, R2, and Cache rules permissions"
  type        = string
  sensitive   = true
}

variable "zone_hostname" {
  description = "The hostname of the Cloudflare zone (e.g. example.com)"
  type        = string
}
