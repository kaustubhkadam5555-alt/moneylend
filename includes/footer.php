<?php
/**
 * MoneyLend - Reusable Page Footer
 * 
 * Closes the layout wrappers, displays application copyright,
 * and loads Bootstrap 5 Bundle and custom scripts.
 */
?>
        <!-- Footer Area -->
        <footer class="app-footer text-center text-md-between d-flex flex-column flex-md-row align-items-center justify-content-between">
            <div class="mb-2 mb-md-0">
                &copy; <?php echo date('Y'); ?> <strong class="text-dark">MoneyLend</strong>. Simple Lending. Smarter Tracking.
            </div>
            <div class="small">
                <span>Phase 1 — Foundation &amp; UI Shell</span>
            </div>
        </footer>
    </main>
</div> <!-- End .app-wrapper -->

<!-- Bootstrap 5 JS Bundle CDN (Popper included) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- MoneyLend Custom Script -->
<script src="<?php echo BASE_URL; ?>assets/js/script.js"></script>
</body>
</html>
