// next.config for the standalone SSR theme (GW-506).
// No rewrites to Laravel — the only upstream is the Gateway at /gateway/v1
// (same-origin by default). Cross-origin deployments set GATEWAY_BASE_URL to
// an HTTPS origin listed in GATEWAY_ALLOWED_ORIGINS.
/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Security headers: strict CSP, no inline scripts (GW-501 §6 / TP-9).
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'" },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ]
  },
}
export default nextConfig
