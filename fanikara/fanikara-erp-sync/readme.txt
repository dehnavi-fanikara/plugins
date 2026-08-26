=== Fanikara ERP Sync ===
Contributors: fanikara
Requires at least: 5.8
Requires PHP: 7.4
Version: 1.0.7

Synchronizes the `fanikar` post type with Fanikara ERP.

== Configuration ==

Configure the ERP endpoint and token under Settings > Fanikara ERP Sync.

Defaults:

* Endpoint: `https://fkcerp.ir/web/site/api-wordpress-integration`
* Token: configured with the ERP integration token and editable from the settings page

The endpoint must return the WordPress integration contract from:

`/site/api-wordpress-integration`

The plugin uses these existing WordPress fields and taxonomies:

* `fnk_user_erp_id`
* `fnk_usr_cpt_work_status`
* `fnk_usr_cpt_direct_call_phone_number_for_sms`
* `fnk_usr_cpt_direct_call_phone_number`
* `fnk_usr_reg_date`
* `fnk_usr_work_hours_start`
* `fnk_usr_work_hours_start_end`
* `fnk_usr_done_services`
* `fnk_dev_service_scores` as an associative array of WordPress service term ID to ERP priority
* `fnk_area_city_erp_id` on the `area` taxonomy
* `fnk_service_erp_id` on the `service` taxonomy

City terms are matched by exact ERP city name when their ERP ID field is empty or outdated; the matching term's `fnk_area_city_erp_id` is then updated.

== Safety ==

Blacklisted ERP technical persons are not imported. Existing matching posts are changed to draft, marked as busy, and detached from `area` and `service`; they are not deleted.

The plugin matches existing technical posts by `fnk_user_erp_id` first and normalized phone number only for initial migration.

After each manual or scheduled sync, the settings page keeps a persistent diagnostic report with HTTP errors, invalid JSON, missing post types/taxonomies, mapping conflicts, phone conflicts, and change counts.

== Schedules ==

The plugin maintains three independent schedules, configurable from the settings page:

* Technical status: every 1 minute by default; updates only `fnk_usr_cpt_work_status`.
* Full technical data: every 7 days by default; updates names, phones, ERP IDs, cities, services, and mappings.
* Negative `wpCode` archive: every 7 days by default; changes matching posts to draft and restores posts when the ERP `wpCode` is no longer negative.

Each schedule accepts a numeric value and a unit of minutes, hours, or days from the plugin settings page. Existing minute/day settings are migrated automatically.

Full technical sync maps the first `order.orderDate` for each technical person to `fnk_usr_reg_date`, maps ERP `start_time` and `end_time` to the configured work-hour fields, and maps the count of orders in technical statuses DONE or SETTLED to `fnk_usr_done_services`.

The same full sync maps active `financialPercentage.priority` values from ERP service IDs to WordPress `service` taxonomy term IDs and stores the resulting associative array in `fnk_dev_service_scores`.

The ERP integration endpoint supports `scope=full`, `scope=status`, and `scope=wpcode`.

ERP responses are cached per scope. Manual sync and connection tests request a fresh response and bypass the ERP cache.

Activity category ERP IDs `59` and `102` are excluded from the integration. Matching WordPress service terms with those ERP IDs are removed during a full sync.
