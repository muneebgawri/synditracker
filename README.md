# Synditracker

Synditracker is a WordPress-based content syndication and tracking system designed to monitor and manage distributed content across partner networks.

## Repository Structure

This repository combines the two core components of the Synditracker system:

- **[synditracker-agent](./synditracker-agent)**: The client-side plugin installed on partner websites. It detects new content imports and reports them back to the Hub.
- **[synditracker-core](./synditracker-core)**: The server-side (Hub) plugin that ingests reports, manages content records, and provides a dashboard for tracking.

## Components

### Synditracker Agent
- **Purpose**: Installed on partner sites (cPanel, Managed WP, etc.).
- **Features**: Aggregator detection (Feedzy, WPeMatico), deferred detection scheduling, and a compatibility layer (polyfills) for restrictive server environments.

### Synditracker Core (Hub)
- **Purpose**: Installed on the central management site.
- **Features**: API ingestion endpoints, database management for syndication records, and Discord notification integration.

## Installation

1. For the Hub site: Upload and activate `synditracker-core`.
2. For Partner sites: Upload and activate `synditracker-agent`.

---
*Created for Pinion Partners.*
