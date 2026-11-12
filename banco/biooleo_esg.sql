SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS biooleo_esg
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE biooleo_esg;

CREATE TABLE IF NOT EXISTS perfis_acesso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissoes_paginas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pagina_nome VARCHAR(100) NOT NULL,
    perfil_id INT NOT NULL,
    permitido BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (perfil_id) REFERENCES perfis_acesso(id) ON DELETE CASCADE,
    UNIQUE KEY uq_pagina_perfil (pagina_nome, perfil_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    perfil_id INT NOT NULL,
    telefone VARCHAR(20) NULL,
    cargo VARCHAR(100) NULL,
    cep CHAR(8) NULL,
    logradouro VARCHAR(150) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(80) NULL,
    bairro VARCHAR(80) NULL,
    cidade VARCHAR(80) NULL,
    uf CHAR(2) NULL,
    ponto_referencia VARCHAR(150) NULL,
    status_conta ENUM('Ativa', 'Inativa') DEFAULT 'Ativa',
    data_cadastro DATE NOT NULL,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (perfil_id) REFERENCES perfis_acesso(id),
    INDEX idx_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coletas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    quantidade_litros DECIMAL(10, 2) NOT NULL,
    data_agendada DATE NOT NULL,
    status ENUM('Agendada', 'Pendente', 'Concluida', 'Cancelada') DEFAULT 'Agendada',
    comprovante_caminho VARCHAR(255) NULL,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_coletas_cliente (cliente_id),
    INDEX idx_coletas_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coleta_id INT NOT NULL,
    transportador_id INT NOT NULL,
    origem_tipo ENUM('Comercio', 'Residencia', 'Industria') NOT NULL,
    data_transporte DATE NOT NULL,
    status_entrega ENUM('Em trânsito', 'Entregue', 'Falhou') DEFAULT 'Entregue',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (transportador_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_transportes_coleta (coleta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO perfis_acesso (id, nome) VALUES
(1, 'Administrador'),
(2, 'Operação'),
(3, 'Cliente');

INSERT INTO usuarios (id, nome, email, senha, perfil_id, cargo, data_cadastro) VALUES
(1, 'Gabriel Peres', 'gabriel@lifama.com.br', '$2y$10$VExztovN4e8FhMrJClpL5OYva6mzpMouIpUhUwuQfJdZIBYgziKyO', 1, 'Coordenador ESG', '2026-01-15'),
(2, 'Carlos Mendes (Motorista)', 'carlos@biooleo.com.br', '$2y$10$VExztovN4e8FhMrJClpL5OYva6mzpMouIpUhUwuQfJdZIBYgziKyO', 2, 'Motorista Coletor', '2026-03-20'),
(3, 'Maria Oliveira (Cliente)', 'maria@email.com', '$2y$10$VExztovN4e8FhMrJClpL5OYva6mzpMouIpUhUwuQfJdZIBYgziKyO', 3, 'Residente Parceira', '2026-03-22')
ON DUPLICATE KEY UPDATE senha = VALUES(senha);

INSERT IGNORE INTO permissoes_paginas (pagina_nome, perfil_id, permitido) VALUES
('dashboard_cliente.html', 1, 1),
('dashboard_cliente.html', 2, 1),
('dashboard_cliente.html', 3, 1);

INSERT IGNORE INTO coletas (id, cliente_id, quantidade_litros, data_agendada, status) VALUES
(1, 3, 50.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY),  'Agendada'),
(2, 3, 35.00, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Concluida'),
(3, 3, 40.00, DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'Concluida'),
(4, 3, 50.50, DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'Concluida');

INSERT IGNORE INTO transportes (id, coleta_id, transportador_id, origem_tipo, data_transporte, status_entrega) VALUES
(1, 2, 2, 'Residencia', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Entregue'),
(2, 3, 2, 'Residencia', DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'Entregue'),
(3, 4, 2, 'Residencia', DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'Entregue');

INSERT IGNORE INTO perfis_acesso (id, nome) VALUES (4, 'Visualizar');

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

CREATE TABLE IF NOT EXISTS tentativas_acesso (
    chave CHAR(64) NOT NULL PRIMARY KEY,
    falhas INT NOT NULL DEFAULT 0,
    janela_inicio BIGINT NOT NULL,
    bloqueado_ate BIGINT NOT NULL DEFAULT 0,
    INDEX idx_tentativas_janela (janela_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


CREATE TABLE IF NOT EXISTS pedidos_ajuda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    assunto ENUM('Cadastro', 'Agendamento', 'Acesso', 'Outro', 'Senha') NOT NULL DEFAULT 'Outro',
    mensagem VARCHAR(1000) NULL,
    status ENUM('Aberto', 'Resolvido') NOT NULL DEFAULT 'Aberto',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_resolucao DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_ajuda_status (status, data_criacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


UPDATE usuarios SET cep = '87200000', logradouro = 'Avenida Paraná', numero = '1200', complemento = 'Sala 3',
       bairro = 'Centro', cidade = 'Cianorte', uf = 'PR', ponto_referencia = 'Em frente à praça'
 WHERE id = 3 AND cep IS NULL;
