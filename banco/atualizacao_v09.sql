USE biooleo_esg;

CREATE TABLE IF NOT EXISTS vantagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NULL,
    pontos INT NOT NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resgates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    vantagem_id INT NOT NULL,
    titulo VARCHAR(100) NOT NULL,
    pontos INT NOT NULL,
    status ENUM('Solicitado', 'Entregue', 'Cancelado') NOT NULL DEFAULT 'Solicitado',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (vantagem_id) REFERENCES vantagens(id),
    INDEX idx_resgates_cliente (cliente_id),
    INDEX idx_resgates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO vantagens (id, titulo, descricao, pontos) VALUES
(1, 'Selo Parceiro Bio Óleo', 'Selo digital para divulgar no seu estabelecimento.', 30),
(2, 'Kit sabão ecológico', 'Sabão feito com óleo reciclado, retirado com a equipe.', 50),
(3, 'Coleta prioritária', 'Sua próxima solicitação é atendida primeiro.', 80);
