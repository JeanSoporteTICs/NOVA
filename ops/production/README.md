# Producción: artefacto y DocumentRoot

Este directorio implementa únicamente PROD-01. No despliega ni rota secretos.

## Reglas

- El repositorio debe estar limpio y el origen debe ser un commit/tag.
- El artefacto se construye por allowlist, no copiando el working tree.
- `.env`, datos runtime, tests, herramientas, dumps, logs y backups no entran.
- La única raíz HTTP válida es `<release>/public`.
- Los ejemplos Apache/Nginx son plantillas; requieren validación operacional.

## Construcción

```bash
ops/production/build-release.sh --ref <tag-o-commit> --output /ruta/absoluta/nueva
```

`PHP_BINARY` permite seleccionar el PHP de build. El proceso instala Composer desde
`composer.lock`, ejecuta `npm ci`, construye assets, genera `MANIFEST.sha256` y llama
al verificador. `--dependency-source /ruta` permite una validación local aislada
reutilizando `vendor/` y `public/build/`; no debe utilizarse para el artefacto oficial,
que debe reconstruir todo desde los lockfiles en CI.

## Verificación

```bash
ops/production/verify-release.sh /ruta/absoluta/del/release
```

El scan falla por archivos prohibidos, datos en `storage`, material de clave privada,
Bearer tokens literales, URLs tokenizadas/firmadas o checksums inválidos. Un PASS no
reemplaza el secret scanner corporativo ni las pruebas desde staging.

## Logs y evidencia histórica

Respaldos y logs históricos deben copiarse a almacenamiento forense cifrado, con
acceso restringido, retención y hash, antes de dejar de trackearlos. No deben moverse
a otra carpeta dentro del web root. Las URLs deben registrarse sin query string; los
headers `Authorization`, cookies, tokens, passwords y payloads JWT deben reemplazarse
por `[REDACTED]`.
