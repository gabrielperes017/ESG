USE biooleo_esg;

SET @ja_existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'cep');
SET @comando := IF(@ja_existe = 0,
    'ALTER TABLE usuarios
        ADD COLUMN cep CHAR(8) NULL AFTER telefone,
        ADD COLUMN logradouro VARCHAR(150) NULL AFTER cep,
        ADD COLUMN numero VARCHAR(20) NULL AFTER logradouro,
        ADD COLUMN complemento VARCHAR(80) NULL AFTER numero,
        ADD COLUMN bairro VARCHAR(80) NULL AFTER complemento,
        ADD COLUMN cidade VARCHAR(80) NULL AFTER bairro,
        ADD COLUMN uf CHAR(2) NULL AFTER cidade,
        ADD COLUMN ponto_referencia VARCHAR(150) NULL AFTER uf',
    'SELECT ''v11 já aplicada'' AS aviso');
PREPARE aplicar FROM @comando;
EXECUTE aplicar;
DEALLOCATE PREPARE aplicar;
