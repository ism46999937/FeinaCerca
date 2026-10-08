# Estilos de Feina Cerca

El código fuente de los estilos está en `scss/`. `main.scss` reúne los módulos:

- `_tokens.scss`: colores, tipografía, sombras y radios.
- `_base.scss`: estilos globales y accesibilidad.
- `_layout.scss`: estructura general.
- `_components.scss`: filtros, tarjetas, mapa y controles.
- `_responsive.scss`: diseño adaptable.

Para compilar una vez desde la carpeta del proyecto:

```powershell
sass scss/main.scss css/styles.css --style=expanded
```

Para compilar automáticamente mientras editas:

```powershell
sass --watch scss/main.scss:css/styles.css
```

El navegador carga el CSS compilado desde `css/styles.css`; no puede interpretar SCSS directamente.

