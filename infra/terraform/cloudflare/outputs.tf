output "worker_url" {
  description = "URL of the deployed Cloudflare Worker"
  value       = "https://${cloudflare_workers_script.cache_worker.name}.${var.cloudflare_account_id}.workers.dev"
}

output "kv_namespace_id" {
  description = "KV namespace ID used for cache tag storage"
  value       = cloudflare_kv_namespace.cache_tags.id
}

output "r2_bucket_name" {
  description = "R2 bucket name used for HTML page cache storage"
  value       = cloudflare_r2_bucket.cache_bucket.name
}

output "worker_route_id" {
  description = "ID of the Cloudflare Worker Route"
  value       = cloudflare_worker_route.cache_route.id
}
