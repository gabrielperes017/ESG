CREATE USER IF NOT EXISTS 'biooleo_app'@'localhost'  IDENTIFIED BY 'TROQUE_ESTA_SENHA';
CREATE USER IF NOT EXISTS 'biooleo_app'@'127.0.0.1'  IDENTIFIED BY 'TROQUE_ESTA_SENHA';

GRANT SELECT, INSERT, UPDATE ON biooleo_esg.* TO 'biooleo_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON biooleo_esg.* TO 'biooleo_app'@'127.0.0.1';

GRANT DELETE ON biooleo_esg.tentativas_acesso TO 'biooleo_app'@'localhost';
GRANT DELETE ON biooleo_esg.tentativas_acesso TO 'biooleo_app'@'127.0.0.1';

FLUSH PRIVILEGES;
