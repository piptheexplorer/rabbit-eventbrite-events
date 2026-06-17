# Rabbit Eventbrite Events

Version: 1.4.0

A simplified Eventbrite sync plugin for WordPress.

## What changed in 1.4.0

- Removed manual/local event creation.
- Removed partner/multi-organisation sources.
- Keeps one Eventbrite organisation as the source of truth.
- Keeps syncing Eventbrite events into a WordPress custom post type.
- Keeps event cards, filters, single event pages, sync logs, scheduled sync, and Eventbrite checkout modal support.
- Hides the **Add New Event** UI for the synced event post type.

## Setup

1. Upload and activate the plugin.
2. Go to **Settings > Eventbrite Events**.
3. Add your Eventbrite organisation ID and private token.
4. Click **Sync Events Now**.
5. Add the shortcode to a page.

## Shortcodes

Display synced events:

```text
[eventbrite_events limit="6" columns="3"]
```

Display filters:

```text
[eventbrite_events limit="9" columns="3" filters="true"]
```

Use Eventbrite checkout modal on cards:

```text
[eventbrite_events checkout="modal" limit="6" columns="3"]
```

Filter by location/category/date:

```text
[eventbrite_events location="Sunderland" category="Training" from="2026-06-01" to="2026-12-31"]
```

## Event pages

Synced events appear at:

```text
/eventbrite-events/event-name/
```

The archive appears at:

```text
/eventbrite-events/
```

If those URLs 404 after activating, go to **Settings > Permalinks** and click **Save Changes**.
