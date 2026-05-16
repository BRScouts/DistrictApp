<footer class="site-footer">
    <div class="site-footer-inner">

        <div class="footer-top">
            <a href="https://www.irvalscouts.org.uk"
               class="footer-brand"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="Irwell Valley District Scouts website">
                <img src="/assets/img/white-ir-logo.png"
                     alt="Irwell Valley District Scouts"
                     class="footer-logo">
            </a>
        </div>

        <div class="footer-content">
            <div class="footer-legal">
                <p class="footer-description">
                    Irwell Valley District Scouts District Dashboard.
                </p>

                <nav class="footer-links" aria-label="Footer links">
                    <a href="https://www.irvalscouts.org.uk" target="_blank" rel="noopener noreferrer">
                        Website
                    </a>

                    <a href="https://www.irvalscouts.org.uk/data-protection" target="_blank" rel="noopener noreferrer">
                        Data Protection
                    </a>

                    <a href="https://www.irvalscouts.org.uk/terms-and-conditions" target="_blank" rel="noopener noreferrer">
                        Terms and conditions
                    </a>

                    <a href="https://www.irvalscouts.org.uk/cookie-policy" target="_blank" rel="noopener noreferrer">
                        Cookie policy
                    </a>

                    <a href="https://www.irvalscouts.org.uk/ai-policy" target="_blank" rel="noopener noreferrer">
                        AI policy
                    </a>
                </nav>
            </div>

            <a href="https://www.ckenterprises.co.uk"
               class="footer-sponsor"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="CK Enterprises UK website">
                <span class="footer-sponsor-label">Proudly provided by</span>
                <span class="footer-sponsor-name">CK Enterprises</span>
                <span class="footer-sponsor-meta">Digital systems for Scouts and community organisations</span>
            </a>
        </div>

        <div class="footer-bottom">
            <span>&copy; <?= e(date('Y')) ?> Irwell Valley District Scouts.</span>
            <span>Built for local Scouting.</span>
        </div>

    </div>
</footer>

<style>
    .site-footer {
        background:
            radial-gradient(circle at top left, rgba(255, 255, 255, 0.12), transparent 34rem),
            var(--scout-purple);
        color: #ffffff;
        margin-top: 3rem;
    }

    .site-footer-inner {
        max-width: 1240px;
        margin: 0 auto;
        padding: 3rem 2rem 2rem;
    }

    .footer-top {
        display: flex;
        justify-content: center;
        margin-bottom: 2.75rem;
    }

    .footer-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .footer-brand:hover {
        text-decoration: none;
    }

    .footer-logo {
        display: block;
        max-width: 260px;
        width: 100%;
        height: auto;
    }

    .footer-content {
        display: flex;
        justify-content: space-between;
        gap: 2rem;
        align-items: flex-end;
        border-top: 1px solid rgba(255, 255, 255, 0.22);
        padding-top: 2rem;
    }

    .footer-legal {
        max-width: 760px;
        font-size: 0.86rem;
        line-height: 1.5;
        color: rgba(255, 255, 255, 0.86);
    }

    .footer-description {
        margin: 0;
        font-weight: 800;
    }

    .footer-links {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem 1.2rem;
        margin-top: 1.3rem;
    }

    .footer-links a {
        color: #ffffff;
        font-weight: 900;
        text-decoration: none;
        font-size: 0.82rem;
        line-height: 1.2;
    }

    .footer-links a:hover {
        text-decoration: underline;
    }

    .footer-sponsor {
        display: block;
        min-width: 280px;
        max-width: 340px;
        padding: 1.15rem 1.25rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #ffffff;
        text-decoration: none;
        box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.14);
        transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
    }

    .footer-sponsor:hover {
        color: #ffffff;
        text-decoration: none;
        background: rgba(255, 255, 255, 0.18);
        transform: translateY(-2px);
        box-shadow: 0 1.25rem 2.5rem rgba(0, 0, 0, 0.2);
    }

    .footer-sponsor-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: rgba(255, 255, 255, 0.76);
        margin-bottom: 0.35rem;
    }

    .footer-sponsor-name {
        display: block;
        font-size: 1.45rem;
        line-height: 1;
        font-weight: 900;
        letter-spacing: -0.04em;
    }

    .footer-sponsor-name::after {
        content: "UK";
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: 0.45rem;
        padding: 0.18rem 0.38rem;
        border-radius: 999px;
        background: #ffffff;
        color: var(--scout-purple);
        font-size: 0.65rem;
        font-weight: 900;
        letter-spacing: 0.02em;
        vertical-align: middle;
    }

    .footer-sponsor-meta {
        display: block;
        margin-top: 0.5rem;
        font-size: 0.82rem;
        line-height: 1.35;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.82);
    }

    .footer-bottom {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        margin-top: 2rem;
        padding-top: 1.25rem;
        border-top: 1px solid rgba(255, 255, 255, 0.16);
        color: rgba(255, 255, 255, 0.76);
        font-size: 0.78rem;
        font-weight: 800;
    }

    @media (max-width: 767.98px) {
        .site-footer-inner {
            padding: 2.5rem 1.25rem 1.75rem;
        }

        .footer-logo {
            max-width: 220px;
        }

        .footer-content {
            display: block;
        }

        .footer-sponsor {
            margin-top: 2rem;
            min-width: 0;
            max-width: none;
        }

        .footer-bottom {
            display: block;
        }

        .footer-bottom span {
            display: block;
            margin-top: 0.35rem;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>