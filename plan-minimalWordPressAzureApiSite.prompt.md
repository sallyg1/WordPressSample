## Plan: Minimal WordPress Azure API Site

Build a very small WordPress site locally with LocalWP, keep the implementation focused on one custom API integration, and defer anything not needed for a first working version. The recommended approach is a LocalWP-managed WordPress site plus a small custom plugin that performs the Azure REST API call server-side in PHP and renders the result on a single home page.

**Steps**
1. Confirm the v1 scope as: one LocalWP WordPress site, one home page, one API-driven content section, and only basic site branding. Exclude blog, search, user accounts, forms, multilingual support, and e-commerce.
2. Set up LocalWP and create a new local WordPress site. Use a current PHP version that you can later match in Azure App Service.
3. Organize custom code so the Azure API integration lives in a small custom plugin. Use a lightweight theme or child theme only for branding and layout. Do not put the API logic in WordPress core or directly in a third-party theme.
4. Define the Azure API contract before coding: endpoint URL, method, headers, auth mechanism, expected JSON shape, timeout rules, and failure cases. This is the main dependency for the plugin implementation.
5. Implement the Azure API call in the custom plugin using WordPress HTTP APIs such as `wp_remote_get` or `wp_remote_post`. Centralize the request logic and expose the output through a shortcode for the home page. This is the simplest first version.
6. Add configuration handling for the API base URL and any API keys or tokens. Keep secrets out of templates and avoid hardcoding environment-specific values.
7. Build the single-page experience in WordPress: simple header/footer, one content area, and one API-driven section that renders the Azure response in a clear format.
8. Add fallback behavior so the page still renders if the Azure API fails. Show a user-friendly message instead of raw PHP errors, and log enough detail for debugging.
9. Test everything locally in LocalWP: successful response, invalid response, timeout, API outage, plugin activation, and page rendering on desktop and mobile.
10. Prepare a manual deployment runbook for later Azure hosting. Since you do not want CI/CD, document the manual deployment steps and the production settings needed in Azure App Service.

**Relevant files**
- [implementation-plan.md](implementation-plan.md) — Existing broader planning document that can be narrowed to this LocalWP + Azure API scope if you want a workspace file updated later.
- LocalWP site folder under your LocalWP-managed WordPress installation — This will contain the future plugin and theme files.
- `wp-content/plugins/<plugin-name>/` — Recommended location for the Azure API client logic, shortcode registration, config handling, and error handling.
- `wp-content/themes/` — Use only for minimal presentation changes.

**Verification**
1. Create the site in LocalWP and verify WordPress admin and the public home page both load.
2. Activate the custom plugin and verify the Azure API data renders on the home page.
3. Disconnect or misconfigure the API temporarily and confirm the page shows a friendly fallback state.
4. Verify API settings can be changed without editing templates.
5. Check the page manually on desktop and mobile widths.
6. Document the manual Azure deployment prerequisites so the local build can move to App Service later without redesign.

**Decisions**
- Included: LocalWP local development, single-page site, server-side Azure REST API call, minimal custom WordPress code, and manual deployment readiness.
- Excluded: CI/CD, staging environment, Blob Storage, advanced theme architecture, nonessential plugins, and complex site features.
- Recommended approach: put API logic in a custom plugin, not the theme.
- Recommended rendering model: shortcode on the home page, because it is simpler than building a custom block for v1.

**Further Considerations**
1. The Azure API authentication model matters. A static API key is straightforward; OAuth or Entra-based auth increases complexity substantially.
2. If the API is slow or rate-limited, add short-term caching with WordPress transients before adding more infrastructure.
3. Define the expected response schema early so the page template stays stable.
