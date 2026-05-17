<?php
if (!function_exists('ccg_admin_nav_class')) {
    function ccg_admin_nav_class($script, $current)
    {
        return $script === $current ? 'nav-link is-active' : 'nav-link';
    }
}
$ccg_current_script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'editor.php');
$ccg_csrf = isset($csrf_token) ? $csrf_token : admin_csrf_token();
$ccg_current_user = function_exists('admin_current_user') ? admin_current_user() : null;
$ccg_can_manage_site = function_exists('admin_user_can_manage_site') ? admin_user_can_manage_site($ccg_current_user) : false;
?>
<div class="sidebar">
    <h2>RoomKeeper</h2>
    <p class="sidebar-user">Hola, <?php echo htmlspecialchars(isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : '', ENT_QUOTES, 'UTF-8'); ?></p>
    <hr class="sidebar-divider">
    <a href="calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Calendario sala computación</a>
    <?php if ($ccg_can_manage_site): ?>
    <a href="editor.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('editor.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Configuración general</a>
    <a href="security.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('security.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Seguridad y accesos</a>
    <a href="correo-avisos.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('correo-avisos.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Correo y avisos institucionales</a>
    <?php if (function_exists('admin_maintenance_tools_enabled') && admin_maintenance_tools_enabled()): ?>
    <a href="mail-test-calendar.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('mail-test-calendar.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Prueba de envío SMTP</a>
    <a href="sql.php" class="<?php echo htmlspecialchars(ccg_admin_nav_class('sql.php', $ccg_current_script), ENT_QUOTES, 'UTF-8'); ?>">Mantenimiento MySQL</a>
    <?php endif; ?>
    <form class="nav-form" method="POST" action="rebuild.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($ccg_csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="nav-link btn btn-success">Publicar cambios</button>
    </form>
    <?php endif; ?>
    <a href="index.php?logout=1" class="nav-link nav-link--muted">Cerrar sesión</a>
</div>
