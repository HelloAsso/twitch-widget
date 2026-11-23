-- Retire les paramètres d'URL (ex. `?_gl=...` de Google Analytics) copiés avec les slugs, voir Stream::normalizeSlug().
-- Avec eux, tous les appels à l'API HelloAsso du stream échouaient (erreur 500 sur les widgets).
UPDATE {prefix}charity_stream
SET form_slug = TRIM(BOTH '/' FROM TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(form_slug, '?', 1), '#', 1))),
    organization_slug = TRIM(BOTH '/' FROM TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(organization_slug, '?', 1), '#', 1)))
WHERE form_slug LIKE '%?%' OR form_slug LIKE '%#%' OR form_slug LIKE '%/%' OR form_slug <> TRIM(form_slug)
   OR organization_slug LIKE '%?%' OR organization_slug LIKE '%#%' OR organization_slug LIKE '%/%' OR organization_slug <> TRIM(organization_slug);
