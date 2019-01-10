

useGlobalSender plugin for OJS 3. Requires the master branch version of mail.inc.php.

If force_envelope_sender is enabled and envelope_sender address given, the plugin will replace all from fields with the envelope_sender address and move the users address to a reply-to field.

Based on pull request: https://github.com/pkp/pkp-lib/pull/4165

**Disclaimer: The plugin is experimental and not much tested. It may affect the OJS email log.**


