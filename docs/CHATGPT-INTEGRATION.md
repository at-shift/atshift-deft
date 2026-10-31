# ChatGPT Integration Target

Chosen by the user on 2026-10-31, then expanded to Claude, Gemini and other clients.
See [connection routes](../wordpress/atshift-deft/docs/CONNECTIONS.md); OAuth must
be provider-neutral and preserve per-owner permissions. This is an integration target, not a claim
that alpha.13 already connects to ChatGPT. No cloud connection, deployment,
provider account change or tunnel has been authorized or performed.

## User Experience

Add the site connection in ChatGPT, sign in to WordPress, allow the required
authoring scope and return to the conversation. Publication remains a separate
grant. The user should not copy a secret into conversation or project Markdown.
Then read the AI guide, provide the message/materials and optional design image,
review a private page proposal and explicitly choose publication timing.

## Confirmed Gaps

1. Authentication: ChatGPT's authenticated custom MCP path expects OAuth, not
   customer-supplied API keys. The current sfc_ Bearer endpoint is for compatible
   custom-header clients and the integration SDK, not direct ChatGPT setup.
   Add standards-based authorization discovery and a maintained OAuth implementation
   with PKCE, exact issuer/resource/client/redirect checks, explicit account consent,
   short-lived tokens, revocation and no cross-account identity substitution. Do not
   implement a fake token exchange wrapping a static key or remove authentication.
2. Attachments: ChatGPT tools declare top-level file inputs using openai/fileParams.
   The documented file object includes download_url, file_id and optional
   mime_type/file_name. Current prepare-file-transfer expects the client to supply
   exact bytes separately. A ChatGPT adapter must validate authorized downloads,
   enforce SSRF/redirect/size/MIME boundaries and reuse private storage checks.
   Do not add a generic arbitrary-URL downloader or publish source materials by default.
3. Preview: current get-preview returns private HTML data, not a registered ChatGPT
   UI resource. Add a sandboxed host-supported viewer and a readable fallback;
   verify actual display and file handling in ChatGPT, not only SDK JSON results.
4. Reachability: the current loopback WordPress is not directly cloud-reachable.
   Real testing needs an approved HTTPS staging site or separately authorized
   private tunnel. Do not expose this machine or change a production site implicitly.

## Acceptance

- Connect as a non-admin owner without pasting credentials into chat.
- Show requested authoring/publication scopes accurately and allow cancellation.
- Resume only that owner's draft; another user's IDs remain inaccessible.
- Reject expired/revoked/wrong-resource tokens and reused authorization codes.
- Read source material without automatically uploading it; transfer only selected
  publication images/PDFs, with safe reference-only alternatives.
- Display a private saved-version preview in ChatGPT and preserve warnings.
- Never treat a draft request or an ambiguous OK as an instruction to publish.
- Run one complete real conversation and record observed outcomes before claiming
  ChatGPT compatibility. Keep alpha.13's working custom-header path compatible.

## Official Sources Checked

- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/plugins/reference
- https://developers.openai.com/plugins/deploy/connect-chatgpt

These requirements were checked on 2026-10-31. Recheck the official documentation
and actual client interface before implementing or configuring provider-specific steps.
