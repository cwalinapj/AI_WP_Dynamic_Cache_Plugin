variable "do_token" {
  description = "DigitalOcean personal access token"
  type        = string
  sensitive   = true
}

variable "environment" {
  description = "Deployment environment (e.g. production, staging)"
  type        = string
  default     = "production"
}

variable "region" {
  description = "DigitalOcean datacenter region slug (e.g. nyc3, ams3, sgp1)"
  type        = string
  default     = "nyc3"
}

variable "server_size" {
  description = "DigitalOcean droplet size slug (e.g. s-2vcpu-4gb)"
  type        = string
  default     = "s-2vcpu-4gb"
}

variable "ssh_public_key" {
  description = "SSH public key material for server access"
  type        = string
}

variable "admin_ips" {
  description = "List of CIDR ranges allowed to SSH into the server"
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "enable_floating_ip" {
  description = "Allocate and assign a floating IP to the droplet"
  type        = bool
  default     = false
}
