USE biooleo_esg;

ALTER TABLE pedidos_ajuda
    MODIFY assunto ENUM('Cadastro', 'Agendamento', 'Acesso', 'Outro', 'Senha') NOT NULL DEFAULT 'Outro';

