-- Migration gf_ms/002: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_ms TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tb_user TO gf_web;
GRANT USAGE, SELECT ON SEQUENCE public.tb_user_idnum_seq TO gf_web;
GRANT EXECUTE ON FUNCTION public.account_login(character varying, character varying, character varying) TO gf_web;
