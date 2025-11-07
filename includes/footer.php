    </div> <!-- End container-fluid -->
    <footer class="footer-modern mt-auto">
        <div class="container-fluid px-4 py-5">
            <div class="row g-4">
                <!-- Brand Section -->
                <div class="col-lg-4 col-md-6 mb-4 mb-lg-0">
                    <div class="footer-brand mb-3">
                        <div class="d-flex align-items-center mb-3">
                            <img src="<?php echo $basePath; ?>images/logo.jpg" alt="Logo" class="footer-logo me-3">
                            <div>
                                <h5 class="mb-0 fw-bold text-dark"><?php e('brand_name'); ?></h5>
                                <small class="text-muted"><?php e('brand_tagline'); ?></small>
                            </div>
                        </div>
                        <p class="text-muted small mb-0">
                            <?php echo t('footer_description', 'Professional food management system for restaurants and catering businesses.'); ?>
                        </p>
                    </div>
                </div>
                
                <!-- Quick Links Section -->
                <div class="col-lg-2 col-md-6 mb-4 mb-lg-0">
                    <h6 class="footer-title mb-3 fw-bold">
                        <i class="bi bi-link-45deg me-2 text-primary"></i>
                        <?php echo t('quick_links', 'Quick Links'); ?>
                    </h6>
                    <ul class="footer-links list-unstyled mb-0">
                        <?php if (isLoggedIn()): ?>
                            <?php if (isAdmin()): ?>
                                <li class="mb-2">
                                    <a href="<?php echo $basePath; ?>admin/dashboard.php" class="footer-link">
                                        <i class="bi bi-speedometer2 me-2"></i>
                                        <?php echo t('dashboard', 'Dashboard'); ?>
                                    </a>
                                </li>
                                <li class="mb-2">
                                    <a href="<?php echo $basePath; ?>admin/orders.php" class="footer-link">
                                        <i class="bi bi-cart-check me-2"></i>
                                        <?php echo t('orders_title', 'Orders'); ?>
                                    </a>
                                </li>
                                <li class="mb-2">
                                    <a href="<?php echo $basePath; ?>admin/dishes.php" class="footer-link">
                                        <i class="bi bi-egg-fried me-2"></i>
                                        <?php echo t('dishes_title', 'Dishes'); ?>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="mb-2">
                                    <a href="<?php echo $basePath; ?>user/dashboard.php" class="footer-link">
                                        <i class="bi bi-speedometer2 me-2"></i>
                                        <?php echo t('dashboard', 'Dashboard'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php else: ?>
                            <li class="mb-2">
                                <a href="<?php echo $basePath; ?>auth/login.php" class="footer-link">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    <?php echo t('login', 'Login'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="mb-2">
                            <a href="<?php echo $basePath; ?>index.php" class="footer-link">
                                <i class="bi bi-house-door me-2"></i>
                                <?php echo t('home', 'Home'); ?>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- System Info Section -->
                <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
                    <h6 class="footer-title mb-3 fw-bold">
                        <i class="bi bi-info-circle me-2 text-primary"></i>
                        <?php echo t('system_info', 'System Info'); ?>
                    </h6>
                    <ul class="footer-links list-unstyled mb-0">
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-box-seam me-2 text-primary"></i>
                            <span class="text-muted small"><?php echo t('food_management_system', 'Food Management System'); ?></span>
                        </li>
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-calendar-check me-2 text-primary"></i>
                            <span class="text-muted small"><?php echo date('Y'); ?> <?php echo t('version', 'Version'); ?> 1.0</span>
                        </li>
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-shield-check me-2 text-success"></i>
                            <span class="text-muted small"><?php echo t('secure', 'Secure & Reliable'); ?></span>
                        </li>
                    </ul>
                </div>
                
                <!-- Contact/Support Section -->
                <div class="col-lg-3 col-md-6">
                    <h6 class="footer-title mb-3 fw-bold">
                        <i class="bi bi-envelope me-2 text-primary"></i>
                        <?php echo t('contact', 'Contact'); ?>
                    </h6>
                    <ul class="footer-links list-unstyled mb-0">
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-geo-alt-fill me-2 text-primary"></i>
                            <span class="text-muted small"><?php echo t('address', 'Chinese Food Specialist'); ?></span>
                        </li>
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-telephone-fill me-2 text-primary"></i>
                            <span class="text-muted small"><?php echo t('phone', 'Phone'); ?>: +92 XXX XXXXXXX</span>
                        </li>
                        <li class="mb-2 d-flex align-items-center">
                            <i class="bi bi-envelope-fill me-2 text-primary"></i>
                            <span class="text-muted small"><?php echo t('email', 'Email'); ?>: info@hassancook.com</span>
                        </li>
                    </ul>
                    
                    <!-- Social Media Links (Optional) -->
                    <div class="mt-3">
                        <h6 class="footer-title mb-2 fw-bold small"><?php echo t('follow_us', 'Follow Us'); ?></h6>
                        <div class="d-flex gap-2">
                            <a href="#" class="footer-social-link" title="Facebook">
                                <i class="bi bi-facebook"></i>
                            </a>
                            <a href="#" class="footer-social-link" title="Instagram">
                                <i class="bi bi-instagram"></i>
                            </a>
                            <a href="#" class="footer-social-link" title="Twitter">
                                <i class="bi bi-twitter"></i>
                            </a>
                            <a href="#" class="footer-social-link" title="WhatsApp">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer Bottom -->
            <div class="row mt-4 pt-4 border-top">
                <div class="col-12">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                        <div class="text-center text-md-start">
                            <p class="mb-0 text-muted small">
                                <i class="bi bi-copyright me-1"></i>
                                <?php echo date('Y'); ?> <strong><?php e('brand_name'); ?></strong>. <?php echo t('all_rights_reserved', 'All rights reserved.'); ?>
                            </p>
                        </div>
                        <div class="text-center text-md-end">
                            <p class="mb-0 text-muted small">
                                <i class="bi bi-code-slash me-1"></i>
                                <?php echo t('powered_by', 'Powered by'); ?> <strong>Food Management System</strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <?php
    // Determine base path based on file location
    $basePath = '';
    $currentFile = $_SERVER['PHP_SELF'];
    if (strpos($currentFile, '/admin/') !== false || strpos($currentFile, '/user/') !== false || strpos($currentFile, '/auth/') !== false) {
        $basePath = '../';
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo $basePath; ?>assets/js/main.js"></script>
</body>
</html>
