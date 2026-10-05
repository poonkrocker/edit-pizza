<?php
/* Copiá este archivo como  config.php  y editá el token.
   config.php NO debe ser público como texto: al ser .php, el servidor
   lo ejecuta y nunca revela el token. */
return [
  // Token secreto compartido entre el servidor y los editores.
  // Poné una cadena larga y aleatoria. Dejalo '' SOLO si el editor no es accesible por terceros.
  'token' => 'xJGsyljPdnMcy43EZyt3YEXVgiTzWP4QnpZAYERLcVQ',

  // Rutas (por defecto quedan junto a api.php). Descomentá para cambiarlas:
  // 'lib_file'   => __DIR__ . '/ingredients.json',
  // 'pizzas_dir' => __DIR__ . '/data/pizzas',
];