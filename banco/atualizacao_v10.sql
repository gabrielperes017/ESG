USE biooleo_esg;

CREATE TABLE IF NOT EXISTS tentativas_acesso (
    chave CHAR(64) NOT NULL PRIMARY KEY,
    falhas INT NOT NULL DEFAULT 0,
    janela_inicio BIGINT NOT NULL,
    bloqueado_ate BIGINT NOT NULL DEFAULT 0,
    INDEX idx_tentativas_janela (janela_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
