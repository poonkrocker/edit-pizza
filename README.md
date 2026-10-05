# edit-pizza · Editor online de pizzas de Arrabbiata.com.ar

Editor web interactivo de pizzas para **Arrabbiata** (arrabbiata.com.ar).

## Caracteristicas

- **Editor de pizzas (`index.php`):**
  - Colocación libre de toppings con estados entero, mitad y picado.
  - Rotación, escalado, reflejo y ordenación de capas (z-index).
  - Selector de estilos/masas de pizza en tiempo real (Napolitana, New York, Al Molde, A la Plancha y estilos personalizados).
  - Selector de bases salseras con soporte para mitad y mitad (1/2) y texturas/sprites.
  - Salsas en hilo (drizzles) aplicables libremente.
  - Barra de categorías con navegación horizontal táctil y por flechas.
  - Exportador con previsualización para redes sociales (Feed 1:1, 4:5, Historias 9:16), compartir directo en WhatsApp</a> e</a> Instagram, y descarga de pizza con fondo transparente o JSON.
  - Galería interna con carga y guardado en servidor.
  - Modo pantalla limpia para capturas y grabación de video.

- **Editor de ingredientes y estilos (`editor.php`):**
  - Gestión integral de la biblioteca `ingredients.json`.
  - Creación y edición de ingredientes con sprites normalizados (entero/mitad/picado).
  - Creación y ajuste de drizzlers (color, transparencia, curvas).
  - Creación y ajuste de bases (color, textura en mosaico o sprite PNG).
  - Creación y ajuste de estilos de pizza con subida de sprites PNG para masa cruda y cocida, dimensiones en cm, forma geométrica y margen de salsa interactivo.

## Estructura de archivos

```
pizza/
⒔₀ data/
│   └␀ pizzas/           # Archivos JSON de pizzas guardadas
└  .htaccess
└␀ api.php               # Backend PHP para guardado de biblioteca y pizzas
└  config.php              # Configuración de token y rutas
└  db_connect.php         # Conexión opcional a base de datos PDO
└␀ editor.php            # Editor de ingredientes, bases y estilos
└  favicon.png            # Ícono del sitio
└␀ guard.php             # Control de acceso y sesión
└  index.php             # Editor principal de pizzas
└␀ ingredients.json      # Biblioteca de ingredientes, salsas, bases y estilos
└  login.php             # Acceso de administración
```
