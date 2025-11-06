<?php
$isContentOnly = isset($_GET['content_only']) && $_GET['content_only'] == '1';
$conn = null; // Initialize connection variable

try {
    // --- Carga el config PRIMERO, antes del if ---
    require 'config.php'; // <-- AÑADE ESTO AQUÍ

    if (!$isContentOnly) {
        // --- Full Page Load ---
        // (session_start() ya se llamó en config.php)
        require 'check_session.php';
        if ($_SESSION['user_role'] !== 'Admin') {
            header('Location: index.php');
            exit;
        }
        // *** Correct: Use the existing connection file ***
        require 'db_connection.php'; // <-- Esto ahora usará la zona horaria
// ...
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Rutas - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } /* Basic Styles */
        /* Add Tailwind component classes if needed */
        .input-style { margin-top: 0.25rem; display: block; width: 100%; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); padding: 0.5rem; }
        .btn-primary { background-color: #2563eb; color: white; font-weight: 600; padding: 0.5rem 1rem; border-radius: 0.375rem; }
        .btn-primary:hover { background-color: #1d4ed8; }
    </style>
</head>
<body class="bg-gray-100 p-8">
<?php
} else {
        // --- AJAX Content Load ---
        // (config.php ya se llamó arriba)
        if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
            echo '<p class="text-red-500 p-4">Acceso no autorizado.</p>';
            exit;
        }
        // *** Correct: Use the existing connection file ***
        require 'db_connection.php'; // <-- Esto ahora usará la zona horaria
    }
// ...
?>
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Rutas</h1>
             <?php if (!$isContentOnly): ?>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
             <?php endif; ?>
        </div>
 <div class="mb-8 p-4 border rounded-lg">
            <h2 class="text-xl font-semibold mb-4">Agregar Nueva Ruta</h2>
            
            <form id="add-route-form-ajax" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label for="route-name-ajax" class="block text-sm font-medium text-gray-700">Nombre</label>
                    <input type="text" id="route-name-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <div>
                    <label for="route-description-ajax" class="block text-sm font-medium text-gray-700">Descripción</label>
                    <input type="text" id="route-description-ajax" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar</button>
            </form>

        </div>
        <div>
            <h2 class="text-xl font-semibold mb-4">Rutas Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Creación</th></tr>
                    </thead>
                    <tbody id="routes-table-body-ajax"><tr><td colspan="3" class="p-4 text-center text-gray-400">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div> <?php
if (!$isContentOnly) {
    // --- Full Page Load Footer ---
?>
    <script>
        // Define initializeManageRoutes globally for direct load
        function initializeManageRoutes() {
             const apiUrlRoutes = 'api/routes_api.php';
             const routesTbody = document.getElementById('routes-table-body-ajax');
             const addRouteForm = document.getElementById('add-route-form-ajax');
             async function fetchRoutesManage() { /* ... function content ... */ }
             if (addRouteForm) { addRouteForm.addEventListener('submit', async function(e) { /* ... function content ... */ }); }
             fetchRoutesManage();
        }
        document.addEventListener('DOMContentLoaded', initializeManageRoutes);
    </script>
</body>
</html>
<?php
} else {
    // --- AJAX Content Load Footer ---
?>
    <script>
        // Define initializeManageRoutes for AJAX context
        function initializeManageRoutes() {
            const apiUrlRoutes = 'api/routes_api.php';
            const routesTbody = document.getElementById('routes-table-body-ajax');
            const addRouteForm = document.getElementById('add-route-form-ajax');

            async function fetchRoutesManage() {
                if (!routesTbody) { console.error("Route table body not found"); return; }
                try {
                    const response = await fetch(apiUrlRoutes);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const routes = await response.json();
                    routesTbody.innerHTML = '';
                    if (routes.length === 0) {
                        routesTbody.innerHTML = '<tr><td colspan="3" class="text-center p-4 text-gray-500">No hay rutas.</td></tr>';
                    } else {
                        routes.forEach(route => {
                             const row = `
                                <tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${route.name || ''}</td>
                                    <td class="px-6 py-4">${route.description || 'N/A'}</td>
                                    <td class="px-6 py-4 text-xs">${route.created_at ? new Date(route.created_at).toLocaleString('es-CO', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'}) : ''}</td>
                                </tr>`;
                            routesTbody.innerHTML += row;
                        });
                    }
                } catch (error) {
                     console.error('Error fetching routes:', error);
                     if (routesTbody) routesTbody.innerHTML = '<tr><td colspan="3" class="text-center p-4 text-red-500">Error al cargar.</td></tr>';
                 }
            }

            if (addRouteForm) {
                if (!addRouteForm.hasAttribute('data-listener-added')) { // Prevent duplicate listeners
                    addRouteForm.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        const nameInput = document.getElementById('route-name-ajax');
                        const descriptionInput = document.getElementById('route-description-ajax');
                        if (!nameInput) return;
                        const name = nameInput.value;
                        const description = descriptionInput ? descriptionInput.value : null; // Handle optional field
                        try {
                            const response = await fetch(apiUrlRoutes, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, description }) });
                            const result = await response.json();
                            if (!response.ok) throw new Error(result.error || `Error ${response.status}`);
                            if (result.success) { this.reset(); fetchRoutesManage(); }
                            else { alert('Error: ' + result.error); }
                        } catch (error) { console.error('Error adding route:', error); alert(`Error: ${error.message}`); }
                    });
                    addRouteForm.setAttribute('data-listener-added', 'true');
                }
            } else { console.error("Add route form not found"); }

            fetchRoutesManage(); // Initial load
        };
        // Ensure execution
         if (document.readyState === 'complete' || document.readyState === 'interactive') {
             initializeManageRoutes();
         } else {
             setTimeout(initializeManageRoutes, 0); // Fallback timeout
         }
    </script>
<?php
} // End else for AJAX

} catch (Exception $e) {
     error_log("Error in manage_routes.php: " . $e->getMessage());
     echo '<p class="text-red-500 p-4">Error interno al procesar la solicitud.</p>';
 } finally {
     // Ensure connection from db_connection.php is closed
     if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
         $conn->close();
     }
 }
?>