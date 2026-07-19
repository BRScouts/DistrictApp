</main>
<footer class="lt-footer">

    <style>
        .lt-footer {
          
            margin: 3rem 0 0;
            background: #1e1b4b;
            color: #ffffff;
            border-top: none;
        }

        .lt-footer * {
            box-sizing: border-box;
        }

        .lt-footer a {
            color: #ffffff;
            text-decoration: underline;
            text-decoration-thickness: 1px;
            text-underline-offset: 0.18em;
        }

        .lt-footer a:hover {
            color: #e2d9f3;
            text-decoration-thickness: 2px;
        }

        .lt-footer a:focus {
            color: #1e1b4b;
            background: #ffdd00;
            outline: 3px solid #ffdd00;
            outline-offset: 0;
            box-shadow: none;
            text-decoration: none;
        }

        .lt-footer-main {
            width: min(1120px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 2rem 0 1.75rem;
            display: grid;
            gap: 1.5rem;
        }

        .lt-footer-brand {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 1rem;
            min-width: 0;
        }

        .lt-footer-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            min-width: 0;
        }

        .lt-footer-logo {
            display: block;
            width: auto;
            height: 52px;
            max-width: 180px;
            object-fit: contain;
            opacity: 0.9;
        }

        .lt-footer-title {
            margin: 0;
            color: #ffffff;
            font-size: clamp(1.15rem, 2.5vw, 1.5rem);
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: -0.02em;
        }

        .lt-footer-text {
            margin: 0.35rem 0 0;
            max-width: 680px;
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.9rem;
            font-weight: 500;
            line-height: 1.5;
        }

        .lt-footer-meta {
            display: grid;
            gap: 1rem;
            margin-top: 0.25rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
        }

        .lt-footer-meta-text {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.88rem;
            font-weight: 500;
            line-height: 1.5;
        }

        .lt-footer-meta-text span {
            display: block;
            margin-top: 0.1rem;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 600;
        }

        .lt-footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            align-items: center;
        }

        .lt-footer-links a {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            font-weight: 600;
            font-size: 0.88rem;
            line-height: 1.15;
        }

        .lt-footer-created {
            width: 100%;
            background: rgba(0, 0, 0, 0.2);
            color: #ffffff;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .lt-footer-created-link {
            display: block;
            color: #ffffff;
            text-decoration: none;
        }

        .lt-footer-created-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
            text-decoration: none;
        }

        .lt-footer-created-link:focus {
            color: #1e1b4b;
            background: #ffdd00;
            outline: 3px solid #ffdd00;
            outline-offset: -3px;
            box-shadow: none;
        }

        .lt-footer-created-inner {
            width: min(1120px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 0.75rem 0;
            color: inherit;
            font-size: 0.82rem;
            font-weight: 600;
            line-height: 1.35;
            text-align: center;
            letter-spacing: 0.01em;
        }

        .lt-footer-created-inner span {
            color: rgba(255, 255, 255, 0.7);
        }

        .lt-footer-created-link:focus .lt-footer-created-inner span {
            color: #1e1b4b;
        }

        @media (min-width: 768px) {
            .lt-footer-logo {
                height: 56px;
                max-width: 200px;
            }

            .lt-footer-meta {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
            }

            .lt-footer-links {
                justify-content: flex-end;
            }
        }

        @media (max-width: 600px) {
            .lt-footer-main,
            .lt-footer-created-inner {
                width: min(100% - 1rem, 1120px);
            }

            .lt-footer-brand {
                grid-template-columns: 1fr;
                align-items: flex-start;
            }

            .lt-footer-logo {
                height: 44px;
                max-width: 160px;
            }

            .lt-footer-links {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.25rem;
            }

            .lt-footer-links a {
                width: 100%;
                padding: 0.3rem 0;
            }

            .lt-footer-created-inner {
                font-size: 0.78rem;
                text-align: left;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .lt-footer *,
            .lt-footer *::before,
            .lt-footer *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>

    <div class="lt-footer-main">
        <div class="lt-footer-brand">
            <span class="lt-footer-logo-wrap">
                <img
                    class="lt-footer-logo"
                    src="/assets/img/white-ir-logo.png"
                    alt="Irwell Valley District Scouts"
                    onerror="this.style.display='none';"
                >
            </span>

            <div>
                <h2 class="lt-footer-title">Irwell Valley Scout District</h2>
                <p class="lt-footer-text">
                    Supporting volunteers with simple, secure tools for the District.
                </p>
            </div>
        </div>

        <div class="lt-footer-meta">
            <div class="lt-footer-meta-text">
                &copy; <?= e(date('Y')) ?> Irwell Valley Scout District.
                <span>Built for local Scout volunteers.</span>
            </div>

            <nav class="lt-footer-links" aria-label="Footer navigation">
                <a href="/index.php">Dashboard</a>
                <a href="/profile.php">My profile</a>
                <a href="/dc/">District Calendar</a>
                <a href="/logout.php">Sign out</a>
            </nav>
        </div>
    </div>

    <div class="lt-footer-created" aria-label="Creator credit">
        <a
            class="lt-footer-created-link"
            href="https://www.ckenterprises.co.uk/"
            target="_blank"
            rel="noopener noreferrer"
        >
            <div class="lt-footer-created-inner">
                District Dashboard <span>Proudly created by CK Enterprises Group Ltd</span>
            </div>
        </a>
    </div>
</footer>

</body>
</html>