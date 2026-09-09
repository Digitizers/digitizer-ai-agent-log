=== Digitizer AI Agent Log ===
Contributors: benkalsky
Tags: activity log, audit log, ai, rest api, wp-cli
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records what automations changed on your site - REST API, WP-Cron, WP-CLI, XML-RPC. Changes people make in wp-admin are not recorded at all.

== Description ==

An AI agent, a headless front end, a sync script and a cron job all reach WordPress the same way a person never does: over an API. When something on the site is not what you left it as, the question is not "who changed this" but "did a person change this, or did something else".

This plugin answers that question and only that question. It records changes that arrived over the REST API, WP-Cron, WP-CLI or XML-RPC. A change made by a person clicking in wp-admin is not recorded - not filtered out afterwards, not stored and hidden, simply never written.

= Why not a general activity log =

General activity logs record everything, which means the automated change you are looking for is one line in a thousand made by your own team. This one starts from the other end. If you already run an activity log, this sits beside it and answers a different question.

= What it records =

* **The channel** - `rest`, `cron`, `cli` or `xmlrpc`.
* **Which application password** authenticated the request, by name, when there was one. Never a guess: when the name cannot be determined the field is empty rather than filled with a User-Agent or an IP that merely looks like an identity.
* **What changed** - the object, and which fields were touched.
* **When**, in UTC, shown in your site's timezone.

= What it does not record =

* **Not the values.** The names of the fields that changed, never their contents. A log of what your site contains is a second copy of your site.
* **Not people.** A block editor save is a cookie-authenticated REST request, and it is recognised as a person and skipped. This is the plugin's central promise, and it is the case it was hardest to get right.
* **Not reads.** Something polling the REST API would fill the table in a day and drown the writes that were the reason to look.
* **No IP addresses.**

= Reading the log =

On its own admin screen, filtered by channel, object type and date range. Or over the REST API at `digitizer-ai-agent-log/v1/activity`, which requires `manage_options`. There is deliberately no route that deletes: a log that can be erased through the API is a log an attacker erases on the way out.

= Multisite =

Each site keeps its own log, in its own table, and a run that switches between sites files each change under the site it happened on.

== Installation ==

1. Install and activate. The log table is created the first time the plugin runs.
2. Find the log under **Agent Activity** in the admin menu.

== Frequently Asked Questions ==

= Does it slow the site down? =

Changes are held in memory during the request and written once, at shutdown, and only when the request came in on a channel worth recording. A browser request writes nothing at all.

= Does it send anything anywhere? =

No. Nothing leaves the site.

= How large does the table get? =

Old rows are pruned automatically, at most once an hour.

= A plugin on my site rewrites its own settings and fills the log. Can I silence it? =

Yes, with a filter. Nothing is filtered out by default, because what is absent from this log is supposed to mean it did not happen over an API:

`add_filter( 'digitizer_ai_agent_log_record', function ( $record, $entry ) {
    if ( 'elementor_library' === $entry['object_subtype'] ) {
        return false;
    }
    return $record;
}, 10, 2 );`

The entry carries `object_type`, `object_subtype`, `object_id`, `object_name`, `action`, `fields`, `blog_id`, `channel`, `app` and `user_id`, so a rule can match on who made the change as well as what it touched. On a network the filter runs inside the site the change happened on, so a callback may read that site's own options to decide.

Note that such an entry is not a mistake: WordPress refuses an identical meta write before the plugin ever sees it, so a change that reaches the log did alter the row. It is simply a change you may not care about, and only your site can say which those are.

= What happens when I uninstall it? =

The table is dropped and both of its options are deleted, on every site of a network. Nothing is left behind.

== Screenshots ==

1. The log: what each change arrived on, which application password authenticated it, what it touched and which fields. The same entries are readable over the REST API.

== Changelog ==

= 1.2.1 =
* 1.2.0 recognised only half of Elementor's kit copy. Elementor saves the kit's post row as well as its settings when it mirrors the site name, and that save still produced a "Default Kit" row with no fields beside the option change. Both halves are now recognised for what they are.

= 1.2.0 =
* The site tagline (`blogdescription`) is recorded when it changes, beside the site name.
* Elementor copies the site name and tagline into its active kit whenever they change. That copy no longer gets a row of its own next to the option change that caused it - one action, one row. A kit written on its own, or together with anything else, is recorded as before.

= 1.1.0 =
* A `digitizer_ai_agent_log_record` filter, applied to every entry once the channel and the application name are known, so a site can silence a writer it does not care about. Nothing is filtered by default.

= 1.0.1 =
* The plugin page gets its screenshot. No functional change.

= 1.0.0 =
* First release.
