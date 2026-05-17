-- Migration gf_gs/001: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_gs TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, UPDATE ON public.player_characters TO gf_web;
GRANT SELECT, UPDATE ON public.elf1 TO gf_web;
