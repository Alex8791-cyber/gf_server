-- Migration gf_ms/001: bcrypt password hashing.
-- Widens tb_user password columns to text, converts any existing plaintext
-- passwords to bcrypt, and rewrites account_login() to verify with bcrypt.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

ALTER TABLE public.tb_user ALTER COLUMN pwd TYPE text;
ALTER TABLE public.tb_user ALTER COLUMN password TYPE text;

-- Convert existing plaintext passwords to bcrypt. Idempotent: rows that
-- already hold a bcrypt hash ($2a$/$2b$/$2y$) are skipped, so re-running
-- this migration (or applying it to already-hashed data) is safe.
UPDATE public.tb_user
   SET pwd = crypt(pwd, gen_salt('bf', 12))
 WHERE pwd IS NOT NULL
   AND pwd !~ '^\$2[aby]\$';

UPDATE public.tb_user
   SET password = pwd
 WHERE password IS DISTINCT FROM pwd;

-- account_login(): verbatim from gf_ms.sql, with pPwd typed text and the
-- password comparison switched to a bcrypt check.
CREATE OR REPLACE FUNCTION public.account_login(character varying, character varying, character varying) RETURNS public.res_set
    LANGUAGE plpgsql
    AS $_$declare
ppAccountID ALIAS FOR $1;
pPassword ALIAS FOR $2;
pClientIP ALIAS FOR $3;
pAccountID varchar(20);
pcount int;
pPwd text default null;
pBAuthority int2 default 0;
pGMIP varchar(15) default null;

res res_set;

BEGIN
pAccountID = lower(ppAccountID);
res.nRet=-1;

SELECT INTO pcount count(mid) FROM "tb_user" WHERE mid=pAccountID;
IF pcount =0 THEN --This Account is not exist
res.pIdNum= -1;
res.nRet = 2;
RETURN res;
END IF;

SELECT INTO pPwd,pBAuthority,res.pIdNum pwd,byAuthority,idnum FROM "tb_user" WHERE mid=pAccountID;

IF pPwd IS null THEN
res.nRet = 2;
RETURN res;
ELSEIF pPwd <> crypt(pPassword, pPwd) THEN
res.nRet = 3;
RETURN res;
END IF;

IF pBAuthority = 255 THEN   --This Account was locked
res.nRet = 5;
RETURN res;
END IF;

--IF pBAuthority = 1 THEN   --gmAccount  Check ip (0-->User, 1-->GM, 255-->Locked)
--SELECT INTO pGMIP ip FROM gmip WHERE ip=pClientIP;
--IF pGMIP IS NULL THEN
--res.nRet = 4;
--RETURN res;
--END IF;
--END IF;

res.nRet = 1;
RETURN res;
END;
$_$;
