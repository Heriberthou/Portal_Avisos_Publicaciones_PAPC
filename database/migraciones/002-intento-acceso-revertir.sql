-- =====================================================================
-- PAPC | Reversión de la migración 002
-- Elimina únicamente la tabla intento_acceso (datos temporales de
-- limitación de intentos). No afecta usuarios ni auditoría.
-- Sin esta tabla el login deja de funcionar (falla cerrado).
-- =====================================================================

DROP TABLE intento_acceso;
