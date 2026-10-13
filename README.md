# Moodle Portable

**Servidor Moodle 5.3 LTS portable para Windows y Linux — sin instalar nada.**

*Creado por Omer Lopez.*

Moodle + PHP + MariaDB incluidos en una sola carpeta. Se descomprime, se ejecuta
y funciona: la primera vez se prepara solo (~2 minutos) en español y con
credenciales generadas automáticamente.

## Descarga

El paquete completo (con todo incluido) está en
**[Releases](../../releases)** como `MoodlePortable.zip` (~311 MB):

1. Descarga el zip desde la última Release.
2. Descomprímelo donde quieras.
3. **Windows:** doble clic en `Iniciar_Moodle.bat` · **Linux:** `./iniciar_moodle.sh`
4. Abre `http://localhost:8080` — usuario `admin`, contraseña en
   `state/admin_credentials.txt`.

## Qué contiene el paquete completo

| Componente | Versión |
|---|---|
| Moodle | 5.3 LTS (español incluido) |
| PHP | 8.4 (Windows y Linux) |
| MariaDB | 11.4.7 (puerto 3307) |
| Dirección web | `http://localhost:8080` |

No requiere instalar PHP, MariaDB ni ninguna dependencia. Todo (base de datos,
archivos, logs) vive dentro de la propia carpeta.

## Este repositorio

Aquí vive la parte ligera del proyecto:

- `iniciar_moodle.sh` / `Iniciar_Moodle.bat` — arranque
- `detener_moodle.sh` / `Detener_Moodle.bat` — parada
- `server/` — orquestador PHP multiplataforma (start/stop/common)
- `Manual_de_Usuario_Moodle_Portable.docx` — manual de usuario

El código de Moodle, los binarios de PHP/MariaDB y todo lo demás viajan en el
zip de Releases (por tamaño).

## Variables de entorno opcionales

| Variable | Defecto | Descripción |
|---|---|---|
| `MOODLE_PORT` | `8080` | Puerto HTTP |
| `MOODLE_WWWROOT` | `http://localhost:PUERTO` | URL pública del sitio |
| `MOODLE_BIND` | `127.0.0.1` | Dirección de escucha |
| `DB_PORT` | `3307` | Puerto de MariaDB |
| `OPEN_BROWSER` | activado | `0` = no abrir el navegador |

## Licencias

Moodle (GPL), PHP (PHP License), MariaDB (GPL v2). Binarios oficiales sin
modificar (en Linux con `strip` y poda de plugins no usados).
