/** @type {import('next').NextConfig} */
const nextConfig = {
  // Gera .next/standalone com um server.js próprio e apenas as dependências
  // realmente usadas — a imagem de produção não carrega o node_modules inteiro.
  output: 'standalone',
};

module.exports = nextConfig;
