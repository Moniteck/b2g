# Reglas de Migracion

1. El proyecto Manus original es solo referencia y no se modifica.
2. Todo desarrollo nuevo se realiza en `crm_b2g_laravel`.
3. La migracion es progresiva y en la primera etapa se prioriza frontend.
4. Existe un unico `.env` de trabajo; no crear archivos `.env` adicionales ni duplicar configuraciones.
5. No crear codigo temporal innecesario ni instalar paquetes sin necesidad.
6. No ejecutar suites completas automaticamente; solo verificaciones minimas de compilacion. El usuario realiza pruebas funcionales manuales.
7. Antes de crear componentes revisar los reutilizables y mantener componentes Vue organizados.
8. Mantener maxima paridad visual con Manus, sin eliminar ni inventar funcionalidades salvo necesidad tecnica.
9. Las pantallas no migradas deben permanecer accesibles mediante `Modulo en migracion` y su progreso.
10. Mantener actualizado `MIGRATION_PROGRESS.md`; toda sesion debe leer ambos archivos antes de cambiar codigo.
11. Mantener el proyecto listo para deploy, sin rutas absolutas de Windows ni configuraciones especificas de una PC.
12. No hardcodear secretos, API keys ni credenciales.
