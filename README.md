# sg-dnit

Paquete compartido de los sistemas SG para Paraguay:

- Copia local del **listado público de contribuyentes de la DNIT** (ex SET) y búsqueda por CI / RUC.
- **Cálculo del DV** con el algoritmo oficial (módulo 11).
- Comando `php artisan dnit:importar` (descarga los 10 ZIP oficiales; si falla, no toca los datos actuales).
- `CustomerFormTrait`: campos, validaciones y autocompletado del formulario de cliente (Livewire 2).
- `HasDocumentoPy`: accessors `full_document` ("1234567-8") y `document_label` ("RUC" / "C.I.") para el modelo Customer.

Requiere Laravel 8, PHP con la extensión `zip`, y las tablas `customers`, `document_types` y `tax_conditions` de los sistemas SG.

## Instalación en un proyecto

1. Agregar el repositorio privado en `composer.json`:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/CristhCasco/sg-dnit.git" }
   ]
   ```

2. Instalar:

   ```bash
   composer require cristhcasco/sg-dnit
   php artisan migrate
   php artisan dnit:importar
   ```

   En un servidor nuevo, Composer pedirá un token de GitHub de solo lectura la primera vez
   (`composer config --global github-oauth.github.com <TOKEN>`).

3. Usar los traits:

   ```php
   // app/Models/Customer.php
   use CristhCasco\SgDnit\Concerns\HasDocumentoPy;

   // Componentes Livewire con formulario de cliente
   use CristhCasco\SgDnit\Concerns\CustomerFormTrait;
   ```

## Actualizar

```bash
composer update cristhcasco/sg-dnit
```

## Configuración (opcional)

```bash
php artisan vendor:publish --tag=sg-dnit-config
```

Permite cambiar el modelo de clientes, la URL del listado y el día/hora de la actualización mensual.
