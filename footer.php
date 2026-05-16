<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="footer-brand">
            <div class="footer-mark">⚜</div>
            <div class="footer-brand-name">Scouts</div>
        </div>

        <div class="footer-content">
            <div class="footer-legal">
                <p>
                    © Copyright The Scout Association <?= date('Y') ?>. All rights reserved.
                </p>

                <p>
                    Charity numbers: 306101 (England and Wales) and SC038437 (Scotland).<br>
                    Registered address: The Scout Association, Gilwell Park, Chingford, London, England E4 7QW.
                </p>

                <nav class="footer-links" aria-label="Footer links">
                    <a href="#">Website</a>
                    <a href="#">Data Protection</a>
                    <a href="#">Terms and conditions</a>
                    <a href="#">Cookie policy</a>
                    <a href="#">AI policy</a>
                </nav>
            </div>

            <div class="footer-badge">
                <strong>Investors in People</strong>
                <span>We invest in people Gold</span>
            </div>
        </div>
    </div>
</footer>

<style>
    .site-footer {
        background: var(--scout-purple);
        color: #ffffff;
        margin-top: 2rem;
    }

    .site-footer-inner {
        max-width: 1240px;
        margin: 0 auto;
        padding: 3rem 2rem 2rem;
    }

    .footer-brand {
        text-align: center;
        margin-bottom: 2.75rem;
        font-weight: 900;
    }

    .footer-mark {
        font-size: 2rem;
        line-height: 1;
    }

    .footer-brand-name {
        font-size: 2rem;
        letter-spacing: -0.04em;
    }

    .footer-content {
        display: flex;
        justify-content: space-between;
        gap: 2rem;
        align-items: flex-end;
    }

    .footer-legal {
        max-width: 720px;
        font-size: 0.8rem;
        line-height: 1.45;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.82);
    }

    .footer-legal p {
        margin-bottom: 0.65rem;
    }

    .footer-links {
        display: flex;
        flex-wrap: wrap;
        gap: 1.15rem;
        margin-top: 1.6rem;
        text-transform: none;
    }

    .footer-links a {
        color: #ffffff;
        font-weight: 800;
        text-decoration: none;
        font-size: 0.78rem;
    }

    .footer-links a:hover {
        text-decoration: underline;
    }

    .footer-badge {
        color: #ffffff;
        text-align: left;
        min-width: 260px;
    }

    .footer-badge strong {
        display: block;
        font-size: 1.5rem;
        line-height: 1;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -0.04em;
    }

    .footer-badge span {
        display: block;
        font-size: 1rem;
        font-weight: 800;
        margin-top: 0.2rem;
    }

    @media (max-width: 767.98px) {
        .site-footer-inner {
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }

        .footer-content {
            display: block;
        }

        .footer-badge {
            margin-top: 2rem;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>