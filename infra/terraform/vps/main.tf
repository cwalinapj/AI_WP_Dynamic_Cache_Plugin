terraform {
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.0"
    }
  }
}

provider "digitalocean" {
  token = var.do_token
}

# ---------------------------------------------------------------------------
# SSH Key
# ---------------------------------------------------------------------------
resource "digitalocean_ssh_key" "wp_server" {
  name       = "ai-wp-cache-${var.environment}"
  public_key = var.ssh_public_key
}

# ---------------------------------------------------------------------------
# Droplet (VPS)
# ---------------------------------------------------------------------------
resource "digitalocean_droplet" "wp_server" {
  name     = "ai-wp-cache-${var.environment}"
  region   = var.region
  size     = var.server_size
  image    = "ubuntu-22-04-x64"
  ssh_keys = [digitalocean_ssh_key.wp_server.fingerprint]

  user_data = <<-EOT
    #!/bin/bash
    set -e
    apt-get update -y
    apt-get install -y python3 python3-pip
  EOT

  tags = ["wordpress", "ai-wp-cache", var.environment]
}

# ---------------------------------------------------------------------------
# Firewall
# ---------------------------------------------------------------------------
resource "digitalocean_firewall" "wp_server" {
  name = "ai-wp-cache-${var.environment}"

  droplet_ids = [digitalocean_droplet.wp_server.id]

  # Inbound: SSH
  inbound_rule {
    protocol         = "tcp"
    port_range       = "22"
    source_addresses = var.admin_ips
  }

  # Inbound: HTTP
  inbound_rule {
    protocol         = "tcp"
    port_range       = "80"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }

  # Inbound: HTTPS
  inbound_rule {
    protocol         = "tcp"
    port_range       = "443"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }

  # Outbound: all
  outbound_rule {
    protocol              = "tcp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }

  outbound_rule {
    protocol              = "udp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }

  outbound_rule {
    protocol              = "icmp"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
}

# ---------------------------------------------------------------------------
# Floating IP (optional, for blue/green deployments)
# ---------------------------------------------------------------------------
resource "digitalocean_floating_ip" "wp_server" {
  count  = var.enable_floating_ip ? 1 : 0
  region = var.region
}

resource "digitalocean_floating_ip_assignment" "wp_server" {
  count      = var.enable_floating_ip ? 1 : 0
  ip_address = digitalocean_floating_ip.wp_server[0].ip_address
  droplet_id = digitalocean_droplet.wp_server.id
}

output "server_ip" {
  description = "Public IP address of the VPS"
  value       = digitalocean_droplet.wp_server.ipv4_address
}

output "server_id" {
  description = "Unique ID of the VPS droplet"
  value       = digitalocean_droplet.wp_server.id
}

output "floating_ip" {
  description = "Floating IP address (if enabled)"
  value       = var.enable_floating_ip ? digitalocean_floating_ip.wp_server[0].ip_address : null
}
