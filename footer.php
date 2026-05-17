<footer class="lt-footer">
    <style>
        .lt-footer {
            margin-top: 3rem;
            background: #4d0b93;
            color: #ffffff;
        }

        .lt-footer-main {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 2rem 0 1.5rem;
            display: grid;
            gap: 1.5rem;
        }

        .lt-footer-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 0;
        }

        .lt-footer-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .lt-footer-logo {
            display: block;
            width: auto;
            height: 58px;
            max-width: 190px;
            object-fit: contain;
        }

        .lt-footer-title {
            margin: 0;
            font-size: 1.25rem;
            line-height: 1.15;
            font-weight: 900;
            color: #ffffff;
        }

        .lt-footer-text {
            margin: .35rem 0 0;
            max-width: 680px;
            font-size: .98rem;
            line-height: 1.45;
            font-weight: 700;
            color: rgba(255, 255, 255, .88);
        }

        .lt-footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem 1rem;
            align-items: center;
        }

        .lt-footer-links a {
            color: #ffffff;
            font-weight: 900;
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
        }

        .lt-footer-links a:hover,
        .lt-footer-links a:focus {
            color: #ffb81c;
            outline: 3px solid rgba(255, 184, 28, .55);
            outline-offset: 3px;
        }

        .lt-footer-meta {
            border-top: 1px solid rgba(255, 255, 255, .22);
            margin-top: .5rem;
            padding-top: 1rem;
            display: grid;
            gap: .75rem;
            font-size: .9rem;
            font-weight: 700;
            color: rgba(255, 255, 255, .82);
        }

        .lt-footer-created {
            background: #2f075c;
            color: #ffffff;
            border-top: 1px solid rgba(255, 255, 255, .18);
        }

        .lt-footer-created-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: .85rem 0;
            text-align: center;
            font-size: .9rem;
            font-weight: 900;
            letter-spacing: .01em;
        }

        .lt-footer-created-inner span {
            color: #ffb81c;
        }

        @media (min-width: 768px) {
            .lt-footer-main {
                padding: 2.5rem 0 1.75rem;
            }

            .lt-footer-logo {
                height: 72px;
                max-width: 230px;
            }

            .lt-footer-meta {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
            }

            .lt-footer-links {
                justify-content: flex-end;
            }
        }

        @media (max-width: 480px) {
            .lt-footer-brand {
                align-items: flex-start;
                flex-direction: column;
            }

            .lt-footer-logo {
                height: 54px;
                max-width: 180px;
            }

            .lt-footer-title {
                font-size: 1.15rem;
            }

            .lt-footer-created-inner {
                font-size: .84rem;
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
                    Supporting volunteers with simplexml_load_file tools for the District Directory,
                </p>
            </div>
        </div>

        <div class="lt-footer-meta">
            <div>
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
        <div class="lt-footer-created-inner">
            District Dashboard <span>Proudly Created by CK Enterprises Group Ltd</span>
        </div>
    </div>
</footer>

</body>
</html>