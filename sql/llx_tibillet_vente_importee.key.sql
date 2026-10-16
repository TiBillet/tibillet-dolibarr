-- Une vente n'est recopiee qu'une fois par societe (entity).
-- / A sale is copied only once per company.
ALTER TABLE llx_tibillet_vente_importee ADD UNIQUE INDEX uk_tibillet_vente_importee (vente_uuid, entity);
ALTER TABLE llx_tibillet_vente_importee ADD INDEX idx_tibillet_vente_importee_cloture (cloture_uuid, numero_vente);
