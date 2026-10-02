# Package Distribution Plan

Please refer to the comprehensive distribution architecture and evaluation plan in:
- [15_distribution_plan.md](../../AUDIT/15_distribution_plan.md)

### Summary of Options
1. **Option A (Satis Static Repo):** Automated CI deploy of static metadata to cPanel subdomain; token auth via `.htaccess`.
2. **Option B (Dynamic Composer Endpoint):** Native Laravel routes in `LiencesSite` checking active licenses before streaming package dist.
3. **Option C (Private Packagist SaaS):** Managed commercial service.
4. **Option D (Portal Zip Download):** Direct authenticated zip download from user portal with Composer `path` repository.
