-- Une cloture n'est importee qu'une fois par societe (entity).
-- / A closure is imported only once per company.
ALTER TABLE llx_tibillet_cloture_importee ADD UNIQUE INDEX uk_tibillet_cloture_importee (cloture_uuid, entity);
ALTER TABLE llx_tibillet_cloture_importee ADD INDEX idx_tibillet_cloture_importee_numero (numero);
