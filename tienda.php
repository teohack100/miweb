<?php
require_once 'config.php';

$plataformas = getPlataformas();
$cuentas = getCuentas();

// Filtrar solo cuentas disponibles
$cuentasDisponibles = array_filter($cuentas, fn($c) => $c['estado'] == 'disponible');

// Agrupar cuentas por plataforma
$cuentasPorPlataforma = [];
foreach ($cuentasDisponibles as $cuenta) {
    $pid = $cuenta['plataforma_id'];
    if (!isset($cuentasPorPlataforma[$pid])) {
        $cuentasPorPlataforma[$pid] = [
            'nombre' => $cuenta['plataforma_nombre'],
            'imagen' => $cuenta['imagen_url'],
            'cantidad' => 0,
            'precio_min' => $cuenta['precio'],
        ];
    }
    $cuentasPorPlataforma[$pid]['cantidad']++;
    if ($cuenta['precio'] < $cuentasPorPlataforma[$pid]['precio_min']) {
        $cuentasPorPlataforma[$pid]['precio_min'] = $cuenta['precio'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StreamStore - Cuentas Premium</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-5px); }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- HEADER -->
    <header class="bg-gray-800 border-b border-gray-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <!-- Logo -->
            <a href="tienda.php" class="text-2xl font-bold text-purple-400">StreamStore</a>

            <!-- Search -->
            <div class="hidden md:flex flex-1 max-w-md mx-8">
                <input type="text" placeholder="Buscar plataforma..." 
                    class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-2 text-white placeholder-gray-500 focus:border-purple-500 focus:outline-none">
            </div>

            <!-- Auth Buttons -->
            <div class="flex items-center gap-3">
                <button onclick="openModal('loginModal')" class="px-4 py-2 text-gray-300 hover:text-white transition-colors">
                    Iniciar Sesión
                </button>
                <button onclick="openModal('registerModal')" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg font-medium transition-colors">
                    Registrarse
                </button>
            </div>
        </div>
    </header>

    <!-- HERO BANNER -->
    <section class="bg-gradient-to-r from-purple-900 via-indigo-900 to-purple-900 py-16">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">Cuentas Premium al Mejor Precio</h1>
            <p class="text-xl text-gray-300 mb-8">Netflix, Disney+, HBO Max, Spotify y más...</p>
            <a href="#plataformas" class="inline-block bg-white text-gray-900 px-8 py-3 rounded-lg font-semibold hover:bg-gray-100 transition-colors">
                Ver Catálogo
            </a>
        </div>
    </section>

    <!-- PLATAFORMAS ICONS -->
    <section class="bg-gray-800 py-8 border-b border-gray-700">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex flex-wrap justify-center gap-8">
                <?php foreach ($plataformas as $p): ?>
                <a href="#plataforma-<?= $p['id'] ?>" class="flex flex-col items-center gap-2 text-gray-400 hover:text-white transition-colors group">
                    <div class="w-16 h-16 bg-gray-900 rounded-xl flex items-center justify-center group-hover:bg-gray-700 transition-colors">
                        <img src="<?= htmlspecialchars($p['imagen_url']) ?>" alt="<?= htmlspecialchars($p['nombre']) ?>" class="w-10 h-10 object-contain" onerror="this.parentElement.innerHTML='<span class=\'text-2xl font-bold\'><?= substr($p['nombre'], 0, 1) ?></span>'">
                    </div>
                    <span class="text-sm"><?= htmlspecialchars($p['nombre']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CATÁLOGO DE CUENTAS -->
    <section id="plataformas" class="py-12">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-2xl font-bold mb-8">Cuentas Disponibles</h2>

            <?php if (count($cuentasPorPlataforma) > 0): ?>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                <?php foreach ($cuentasPorPlataforma as $pid => $info): ?>
                <div id="plataforma-<?= $pid ?>" class="card-hover bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                    <!-- Imagen -->
                    <div class="h-32 bg-gray-900 flex items-center justify-center p-4">
                        <img src="<?= htmlspecialchars($info['imagen']) ?>" alt="<?= htmlspecialchars($info['nombre']) ?>" 
                            class="max-h-full max-w-full object-contain" 
                            onerror="this.parentElement.innerHTML='<span class=\'text-4xl font-bold text-gray-600\'><?= substr($info['nombre'], 0, 2) ?></span>'">
                    </div>
                    <!-- Info -->
                    <div class="p-4">
                        <h3 class="font-semibold text-lg mb-1"><?= htmlspecialchars($info['nombre']) ?></h3>
                        <p class="text-gray-400 text-sm mb-3"><?= $info['cantidad'] ?> disponible<?= $info['cantidad'] > 1 ? 's' : '' ?></p>
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-400 font-bold text-xl">$<?= number_format($info['precio_min'], 2) ?></span>
                            <button onclick="openModal('loginModal')" class="px-3 py-1 bg-purple-600 hover:bg-purple-700 rounded text-sm font-medium transition-colors">
                                Comprar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-16 text-gray-500">
                <p class="text-xl">No hay cuentas disponibles en este momento</p>
                <p class="mt-2">Vuelve pronto para ver nuestro catálogo</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- TODAS LAS CUENTAS CON PRECIOS -->
    <?php if (count($cuentasDisponibles) > 0): ?>
    <section class="py-12 bg-gray-800">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-2xl font-bold mb-8">Precios y Ofertas</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($cuentasDisponibles as $cuenta): ?>
                <div class="bg-gray-900 rounded-lg p-4 border border-gray-700 flex items-center gap-4">
                    <img src="<?= htmlspecialchars($cuenta['imagen_url'] ?? '') ?>" alt="" class="w-12 h-12 object-contain" onerror="this.style.display='none'">
                    <div class="flex-1">
                        <p class="font-medium"><?= htmlspecialchars($cuenta['plataforma_nombre']) ?></p>
                        <p class="text-gray-400 text-sm">Cuenta Premium</p>
                    </div>
                    <div class="text-right">
                        <p class="text-emerald-400 font-bold text-lg">$<?= number_format($cuenta['precio'], 2) ?></p>
                        <button onclick="openModal('loginModal')" class="text-purple-400 text-sm hover:text-purple-300">Comprar</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- FOOTER -->
    <footer class="bg-gray-900 border-t border-gray-800 py-8">
        <div class="max-w-7xl mx-auto px-4 text-center text-gray-500">
            <p>&copy; <?= date('Y') ?> StreamStore - Todos los derechos reservados</p>
        </div>
    </footer>

    <!-- MODAL LOGIN -->
    <div id="loginModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="bg-gray-800 rounded-2xl p-8 w-full max-w-md mx-4 border border-gray-700">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold">Iniciar Sesión</h3>
                <button onclick="closeModal('loginModal')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Correo electrónico</label>
                    <input type="email" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Contraseña</label>
                    <input type="password" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition-colors">
                    Ingresar
                </button>
            </form>
            <p class="text-center text-gray-400 mt-4">
                ¿No tienes cuenta? <button onclick="closeModal('loginModal'); openModal('registerModal')" class="text-purple-400 hover:text-purple-300">Regístrate</button>
            </p>
        </div>
    </div>

    <!-- MODAL REGISTRO -->
    <div id="registerModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="bg-gray-800 rounded-2xl p-8 w-full max-w-md mx-4 border border-gray-700">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold">Crear Cuenta</h3>
                <button onclick="closeModal('registerModal')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Nombre completo</label>
                    <input type="text" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Correo electrónico</label>
                    <input type="email" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Contraseña</label>
                    <input type="password" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Confirmar contraseña</label>
                    <input type="password" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:border-purple-500 focus:outline-none">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition-colors">
                    Registrarse
                </button>
            </form>
            <p class="text-center text-gray-400 mt-4">
                ¿Ya tienes cuenta? <button onclick="closeModal('registerModal'); openModal('loginModal')" class="text-purple-400 hover:text-purple-300">Inicia sesión</button>
            </p>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        // Cerrar modal al hacer clic fuera
        document.querySelectorAll('[id$="Modal"]').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal(modal.id);
            });
        });
    </script>
</body>
</html>
