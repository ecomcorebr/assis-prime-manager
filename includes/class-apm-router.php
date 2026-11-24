<?php
if (!defined('ABSPATH')) exit;

class APM_Router {
    const ROUTE_VERSION = '0.9.15';
    const BASE = 'gestao';

    private static $routes = array(
        'dashboard'     => '',
        'agenda'        => 'agenda',
        'crm'           => 'crm',
        'clients'       => 'clientes',
        'records'       => 'prontuarios',
        'inventory'     => 'estoque',
        'notifications' => 'notificacoes',
        'finance'       => 'financeiro',
        'services'      => 'servicos',
        'professionals' => 'profissionais',
        'settings'      => 'configuracoes',
    );

    public static function init() {
        add_action('init', array(__CLASS__, 'rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'template_redirect'), 1);
        add_action('admin_init', array(__CLASS__, 'redirect_legacy_admin'));
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
        add_filter('retrieve_password_message', array(__CLASS__, 'password_reset_message'), 20, 4);
        // A barra de admin do WordPress (visível para quem está logado) é desenhada a partir
        // de um valor decidido e travado bem cedo no carregamento da página (antes de qualquer
        // template_redirect rodar) — por isso o filtro precisa existir desde já, e não só quando
        // a página standalone já está sendo renderizada. Sem isto, nestas páginas (que já
        // removem o style "admin-bar" junto com tudo que não está na lista permitida de
        // isolate_from_theme) a barra aparece como uma lista de links solta e sem estilo no fim
        // da página, quebrando o layout para qualquer funcionário logado que abra o link (ex.:
        // agendamento público, assinatura digital). Verificamos a URL crua porque o WordPress
        // ainda não processou as rewrite rules nesse momento do carregamento.
        add_filter('show_admin_bar', array(__CLASS__, 'maybe_hide_admin_bar'));
    }

    public static function maybe_hide_admin_bar($show) {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = wp_parse_url($uri, PHP_URL_PATH) ?: '';
        $pattern = '#^/(' . preg_quote(self::BASE, '#') . '|agendamento-online|assinatura-digital|preencher-anamnese)(/|$)#';
        return preg_match($pattern, $path) ? false : $show;
    }

    public static function rewrite_rules() {
        add_rewrite_rule('^' . self::BASE . '/?$', 'index.php?apm_system=dashboard', 'top');
        foreach (self::$routes as $page => $slug) {
            if (!$slug) continue;
            add_rewrite_rule('^' . self::BASE . '/' . preg_quote($slug, '/') . '/?$', 'index.php?apm_system=' . $page, 'top');
        }
        add_rewrite_rule('^agendamento-online/?$', 'index.php?apm_booking=1', 'top');
        add_rewrite_rule('^' . self::BASE . '/login/?$', 'index.php?apm_login=1', 'top');
        add_rewrite_rule('^assinatura-digital/([A-Za-z0-9]+)/?$', 'index.php?apm_signature=$matches[1]', 'top');
        add_rewrite_rule('^preencher-anamnese/([A-Za-z0-9]+)/?$', 'index.php?apm_record_fill=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'apm_system';
        $vars[] = 'apm_booking';
        $vars[] = 'apm_login';
        $vars[] = 'apm_signature';
        $vars[] = 'apm_record_fill';
        return $vars;
    }

    public static function maybe_flush() {
        if (get_option('apm_route_version') !== self::ROUTE_VERSION) {
            self::rewrite_rules();
            flush_rewrite_rules(false);
            update_option('apm_route_version', self::ROUTE_VERSION, false);
        }
    }

    public static function page_url($page = 'dashboard') {
        $slug = isset(self::$routes[$page]) ? self::$routes[$page] : '';
        $path = '/' . self::BASE . '/' . ($slug ? $slug . '/' : '');
        return home_url($path);
    }

    public static function booking_url() {
        return home_url('/agendamento-online/');
    }

    public static function signature_url($token) { return home_url('/assinatura-digital/' . rawurlencode($token) . '/'); }

    public static function record_fill_url($token) { return home_url('/preencher-anamnese/' . rawurlencode($token) . '/'); }

    public static function login_url($redirect_to = '') {
        $url = home_url('/' . self::BASE . '/login/');
        if ($redirect_to) $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $url);
        return $url;
    }

    public static function route_map() {
        $out = array();
        foreach (self::$routes as $page => $slug) $out[$page] = self::page_url($page);
        return $out;
    }

    public static function redirect_legacy_admin() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter check, not processing form data.
        if (!is_admin() || !isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'assis-prime') return;
        if (!APM_Repository::user_can_access_system() && !current_user_can('manage_options')) return;
        wp_safe_redirect(self::page_url('dashboard'));
        exit;
    }

    public static function template_redirect() {
        $page = get_query_var('apm_system');
        if ($page) {
            self::render_system($page);
            exit;
        }
        if (get_query_var('apm_booking')) {
            APM_Public::render_document();
            exit;
        }
        if (get_query_var('apm_login')) {
            self::render_login();
            exit;
        }
        if (get_query_var('apm_signature')) {
            self::render_signature(sanitize_text_field(get_query_var('apm_signature')));
            exit;
        }
        if (get_query_var('apm_record_fill')) {
            self::render_record_fill(sanitize_text_field(get_query_var('apm_record_fill')));
            exit;
        }
    }

    private static function render_system($page) {
        if (!is_user_logged_in()) {
            wp_safe_redirect(self::login_url(self::page_url($page)));
            exit;
        }
        if (!APM_Repository::user_can_access_system() && !current_user_can('manage_options')) {
            wp_die('Você não tem permissão para acessar este sistema.', 'Acesso negado', array('response' => 403));
        }
        if (!isset(self::$routes[$page])) $page = 'dashboard';

        self::isolate_from_theme(array('apm-admin', 'apm-font-inter'), array('apm-admin'));
        self::enqueue_brand_font();
        wp_enqueue_style('apm-admin', APM_URL . 'assets/css/admin.css', array(), APM_VERSION);
        wp_enqueue_script('apm-admin', APM_URL . 'assets/js/admin.js', array(), APM_VERSION, true);
        wp_localize_script('apm-admin', 'APM', array(
            'rest'           => esc_url_raw(rest_url('assis-prime/v1/')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'logo'           => APM_URL . 'assets/images/logo.png',
            'siteUrl'        => home_url('/'),
            'bookingPreview' => self::booking_url(),
            'version'        => APM_VERSION,
            'currentPage'    => $page,
            'routes'         => self::route_map(),
            'baseUrl'        => self::page_url('dashboard'),
        ));

        nocache_headers();
        status_header(200);
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title>Assis Prime — Gestão</title>
            <?php
            // O painel isola scripts/estilos registrados via wp_enqueue_scripts (acima), mas
            // não consegue remover <script> inline que o tema ou outro plugin imprima direto
            // em wp_head (ex.: snippet de Analytics chamando gtag() antes da lib carregar, ou
            // a View Transitions API nativa do navegador tentando animar nossa navegação via
            // pushState). Neutraliza os dois aqui, só nesta página, sem mexer no tema.
            ?>
            <script>
            window.gtag = window.gtag || function () { (window.dataLayer = window.dataLayer || []).push(arguments); };
            if (document.startViewTransition) {
                document.startViewTransition = function (cb) {
                    if (typeof cb === 'function') { try { cb(); } catch (e) {} }
                    var done = Promise.resolve();
                    return { ready: done, finished: done, updateCallbackDone: done, skipTransition: function () {} };
                };
            }
            </script>
            <?php wp_head(); ?>
        </head>
        <body class="apm-system-standalone">
            <div id="apm-app" class="apm-root"><div class="apm-loading"><span></span><strong>Carregando Assis Prime…</strong></div></div>
            <div id="apm-toast" class="apm-toast" role="status" aria-live="polite"></div>
            <?php wp_footer(); ?>
        </body>
        </html><?php
    }


    private static function render_signature($token) {
        self::isolate_from_theme(array('apm-signature', 'apm-font-inter'), array('apm-signature'));
        self::enqueue_brand_font();
        wp_enqueue_style('apm-signature', APM_URL . 'assets/css/signature.css', array(), APM_VERSION);
        wp_enqueue_script('apm-signature', APM_URL . 'assets/js/signature.js', array(), APM_VERSION, true);
        wp_localize_script('apm-signature','APMSignature',array('rest'=>esc_url_raw(rest_url('assis-prime/v1/')),'token'=>$token,'logo'=>APM_URL.'assets/images/logo.png'));
        nocache_headers(); status_header(200);
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Assinatura digital — Assis Prime</title><?php wp_head(); ?></head><body class="apm-signature-page"><div id="apm-signature-root"><div class="apm-signature-loading">Carregando documento…</div></div><?php wp_footer(); ?></body></html><?php
    }

    /**
     * Standalone app pages (painel, agendamento público, assinatura, login) are a
     * self-contained front end and must not inherit the active theme's stylesheet,
     * global styles or scripts — themes vary per client and any of them can override
     * our colors, fonts and spacing. This strips everything the theme/other plugins
     * enqueue via wp_enqueue_scripts, keeping only assets this method explicitly allows.
     */
    // Carrega a fonte Inter de verdade (Google Fonts). Sem isto, o navegador do cliente usa
    // o que estiver no sistema operacional dele para "ui-sans-serif/system-ui/sans-serif" — em
    // muitos Windows isso recai no Arial, cujo peso bold em tamanhos pequenos fica "achatado"
    // e pesado demais. Carregar a fonte real deixa a aparência igual em qualquer computador.
    public static function enqueue_brand_font() {
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Google Fonts URL; version is embedded in the URL itself.
        wp_enqueue_style('apm-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null);
    }

    public static function isolate_from_theme($allowed_styles = array(), $allowed_scripts = array()) {
        add_action('wp_enqueue_scripts', function () use ($allowed_styles, $allowed_scripts) {
            global $wp_styles, $wp_scripts;
            if ($wp_styles instanceof WP_Styles) {
                foreach ((array) $wp_styles->queue as $handle) {
                    if (!in_array($handle, $allowed_styles, true)) {
                        wp_dequeue_style($handle);
                        wp_deregister_style($handle);
                    }
                }
            }
            if ($wp_scripts instanceof WP_Scripts) {
                foreach ((array) $wp_scripts->queue as $handle) {
                    if (!in_array($handle, $allowed_scripts, true)) {
                        wp_dequeue_script($handle);
                        wp_deregister_script($handle);
                    }
                }
            }
        }, PHP_INT_MAX);
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
    }

    private static function render_record_fill($token) {
        self::isolate_from_theme(array('apm-record-fill', 'apm-font-inter'), array('apm-record-fill'));
        self::enqueue_brand_font();
        wp_enqueue_style('apm-record-fill', APM_URL . 'assets/css/record-fill.css', array(), APM_VERSION);
        wp_enqueue_script('apm-record-fill', APM_URL . 'assets/js/record-fill.js', array(), APM_VERSION, true);
        wp_localize_script('apm-record-fill', 'APMRecordFill', array('rest' => esc_url_raw(rest_url('assis-prime/v1/')), 'token' => $token, 'logo' => APM_URL . 'assets/images/logo.png'));
        nocache_headers(); status_header(200);
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Anamnese — Assis Prime</title><?php wp_head(); ?></head><body class="apm-fill-page"><div id="apm-record-fill-root"><div class="apm-fill-loading">Carregando formulário…</div></div><?php wp_footer(); ?></body></html><?php
    }

    public static function password_reset_message($message, $key, $user_login, $user_data) {
        $url = add_query_arg(array(
            'mode' => 'reset',
            'key' => rawurlencode($key),
            'login' => rawurlencode($user_login),
        ), self::login_url());
        $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        return "Olá,\n\nRecebemos uma solicitação para redefinir sua senha de acesso ao sistema Assis Prime.\n\nAcesse o link abaixo para criar uma nova senha:\n" . $url . "\n\nSe você não solicitou esta alteração, ignore esta mensagem.\n\n" . $site;
    }

    private static function render_login() {
        $redirect_to = isset($_GET['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['redirect_to']))) : self::page_url('dashboard');
        if (!$redirect_to || strpos($redirect_to, home_url('/')) !== 0) $redirect_to = self::page_url('dashboard');

        if (is_user_logged_in()) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        $mode = isset($_GET['mode']) && in_array($_GET['mode'], array('forgot','reset'), true) ? sanitize_key($_GET['mode']) : 'login';
        $error = '';
        $success = '';
        $rp_login = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';
        $rp_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if ($mode === 'reset' && (!$rp_login || !$rp_key)) { $mode = 'forgot'; $error = 'O link de recuperação está incompleto. Solicite um novo link.'; }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['apm_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apm_login_nonce'])), 'apm_login_action')) {
                $error = 'Não foi possível validar a solicitação. Atualize a página e tente novamente.';
            } elseif (isset($_POST['apm_action']) && $_POST['apm_action'] === 'reset') {
                $mode = 'reset';
                $rp_login = sanitize_text_field(wp_unslash($_POST['rp_login'] ?? ''));
                $rp_key = sanitize_text_field(wp_unslash($_POST['rp_key'] ?? ''));
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized; sanitize_text_field() would corrupt special characters.
                $pass1 = (string) wp_unslash($_POST['pass1'] ?? '');
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized; sanitize_text_field() would corrupt special characters.
                $pass2 = (string) wp_unslash($_POST['pass2'] ?? '');
                $reset_user = check_password_reset_key($rp_key, $rp_login);
                if (is_wp_error($reset_user)) {
                    $error = 'Este link de recuperação é inválido ou expirou. Solicite um novo link.';
                } elseif (!$pass1 || strlen($pass1) < 8) {
                    $error = 'Crie uma senha com pelo menos 8 caracteres.';
                } elseif ($pass1 !== $pass2) {
                    $error = 'As senhas informadas não são iguais.';
                } else {
                    reset_password($reset_user, $pass1);
                    $success = 'Senha atualizada com sucesso. Você já pode entrar no sistema.';
                    $mode = 'login';
                }
            } elseif (isset($_POST['apm_action']) && $_POST['apm_action'] === 'forgot') {
                $mode = 'forgot';
                $user_login = sanitize_text_field(wp_unslash($_POST['user_login'] ?? ''));
                if (!$user_login) {
                    $error = 'Informe seu e-mail ou usuário.';
                } else {
                    $_POST['user_login'] = $user_login;
                    $result = retrieve_password();
                    if (is_wp_error($result)) {
                        $error = $result->get_error_message();
                    } else {
                        $success = 'Enviamos as instruções de recuperação para o e-mail cadastrado.';
                    }
                }
            } else {
                $login = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized; sanitize_text_field() would corrupt special characters.
                $password = (string) wp_unslash($_POST['pwd'] ?? '');
                $remember = !empty($_POST['rememberme']);
                if (!$login || !$password) {
                    $error = 'Informe seu usuário/e-mail e senha.';
                } else {
                    $creds = array('user_login' => $login, 'user_password' => $password, 'remember' => $remember);
                    $user = wp_signon($creds, is_ssl());
                    if (is_wp_error($user)) {
                        $error = 'Usuário ou senha inválidos. Confira os dados e tente novamente.';
                    } else {
                        $access = APM_Repository::get_user_access($user->ID);
                        if (empty($access['enabled']) && empty($access['is_admin'])) {
                            wp_logout();
                            $error = 'Seu usuário não possui acesso ao sistema Assis Prime.';
                        } else {
                            wp_safe_redirect($redirect_to);
                            exit;
                        }
                    }
                }
            }
        }

        self::isolate_from_theme(array('apm-login', 'apm-font-inter'), array());
        self::enqueue_brand_font();
        wp_enqueue_style('apm-login', APM_URL . 'assets/css/login.css', array(), APM_VERSION);
        nocache_headers();
        status_header(200);
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html($mode === 'forgot' ? 'Recuperar acesso — Assis Prime' : ($mode === 'reset' ? 'Criar nova senha — Assis Prime' : 'Entrar — Assis Prime')); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="apm-login-page">
        <main class="apm-login-shell">
            <section class="apm-login-brand-panel">
                <div class="apm-login-brand-inner">
                    <img src="<?php echo esc_url(APM_URL . 'assets/images/logo.png'); ?>" alt="Assis Prime" class="apm-login-logo">
                    <div class="apm-login-brand-copy">
                        <span>GESTÃO INTEGRADA</span>
                        <h1>Cuidado em cada detalhe.<br>Gestão em um só lugar.</h1>
                        <p>Acesse agenda, clientes, prontuários e demais recursos da Assis Prime em um ambiente seguro e exclusivo.</p>
                    </div>
                    <small class="apm-login-brand-foot">Assis Prime Spa e Beleza</small>
                </div>
            </section>
            <section class="apm-login-form-panel">
                <div class="apm-login-card">
                    <div class="apm-login-mobile-logo"><img src="<?php echo esc_url(APM_URL . 'assets/images/logo.png'); ?>" alt="Assis Prime"></div>
                    <?php if ($mode === 'forgot'): ?>
                        <div class="apm-login-kicker">RECUPERAR ACESSO</div>
                        <h2>Esqueceu sua senha?</h2>
                        <p class="apm-login-sub">Informe seu e-mail ou usuário e enviaremos as instruções de recuperação.</p>
                    <?php elseif ($mode === 'reset'): ?>
                        <div class="apm-login-kicker">NOVA SENHA</div>
                        <h2>Crie uma nova senha</h2>
                        <p class="apm-login-sub">Escolha uma senha segura para voltar a acessar o sistema.</p>
                    <?php else: ?>
                        <div class="apm-login-kicker">ÁREA RESTRITA</div>
                        <h2>Acesse sua conta</h2>
                        <p class="apm-login-sub">Entre com seus dados para acessar o sistema.</p>
                    <?php endif; ?>

                    <?php if ($error): ?><div class="apm-login-alert error"><?php echo wp_kses_post($error); ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="apm-login-alert success"><?php echo esc_html($success); ?></div><?php endif; ?>

                    <form method="post" class="apm-login-form">
                        <?php wp_nonce_field('apm_login_action', 'apm_login_nonce'); ?>
                        <input type="hidden" name="apm_action" value="<?php echo esc_attr($mode); ?>">
                        <?php if ($mode === 'reset'): ?>
                            <input type="hidden" name="rp_login" value="<?php echo esc_attr($rp_login); ?>">
                            <input type="hidden" name="rp_key" value="<?php echo esc_attr($rp_key); ?>">
                            <div class="apm-login-field"><label for="apm-pass1">Nova senha</label><input id="apm-pass1" type="password" name="pass1" autocomplete="new-password" minlength="8" required></div>
                            <div class="apm-login-field"><label for="apm-pass2">Confirmar nova senha</label><input id="apm-pass2" type="password" name="pass2" autocomplete="new-password" minlength="8" required></div>
                            <button class="apm-login-submit" type="submit">Salvar nova senha</button>
                        <?php else: ?>
                            <div class="apm-login-field">
                                <label for="apm-login-user">E-mail ou usuário</label>
                                <input id="apm-login-user" type="text" name="<?php echo esc_attr($mode === 'forgot' ? 'user_login' : 'log'); ?>" autocomplete="username" required>
                            </div>
                            <?php if ($mode === 'login'): ?>
                                <div class="apm-login-field">
                                    <div class="apm-login-label-row"><label for="apm-login-pass">Senha</label><a href="<?php echo esc_url(add_query_arg(array('mode'=>'forgot','redirect_to'=>rawurlencode($redirect_to)), self::login_url())); ?>">Esqueci minha senha</a></div>
                                    <div class="apm-password-wrap"><input id="apm-login-pass" type="password" name="pwd" autocomplete="current-password" required><button type="button" class="apm-password-toggle" aria-label="Mostrar senha" onclick="var i=document.getElementById('apm-login-pass');i.type=i.type==='password'?'text':'password';this.classList.toggle('showing')">◉</button></div>
                                </div>
                                <label class="apm-remember"><input type="checkbox" name="rememberme" value="forever"><span>Lembrar de mim neste dispositivo</span></label>
                                <button class="apm-login-submit" type="submit">Entrar no sistema</button>
                            <?php else: ?>
                                <button class="apm-login-submit" type="submit">Enviar instruções</button>
                                <a class="apm-login-back" href="<?php echo esc_url(self::login_url($redirect_to)); ?>">← Voltar para o login</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </form>
                    <div class="apm-login-security"><span>✓</span><p><strong>Acesso protegido</strong>Área exclusiva para usuários autorizados da Assis Prime.</p></div>
                    <footer class="apm-login-footer">Assis Prime · Gestão integrada</footer>
                </div>
            </section>
        </main>
        <?php wp_footer(); ?>
        </body>
        </html><?php
    }
}
