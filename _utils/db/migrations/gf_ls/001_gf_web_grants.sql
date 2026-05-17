-- Migration gf_ls/001: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_ls TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, INSERT, UPDATE ON public.accounts TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.gm_tool_accounts TO gf_web;
