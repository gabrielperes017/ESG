USE biooleo_esg;

CREATE TABLE IF NOT EXISTS pedidos_ajuda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    assunto ENUM('Cadastro', 'Agendamento', 'Acesso', 'Outro') NOT NULL DEFAULT 'Outro',
    mensagem VARCHAR(1000) NULL,
    status ENUM('Aberto', 'Resolvido') NOT NULL DEFAULT 'Aberto',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_resolucao DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_ajuda_status (status, data_criacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
