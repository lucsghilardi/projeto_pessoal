import type { Metadata } from "next";
import { Geist_Mono, Instrument_Sans } from "next/font/google";
import { AppToaster } from "@/components/ui/app-toaster";
import "./globals.css";

const instrumentSans = Instrument_Sans({
  variable: "--font-instrument-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Painel administrativo",
  description: "Painel administrativo — módulo de usuários.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html className="h-full" lang="pt-BR">
      <body className={`${instrumentSans.variable} ${geistMono.variable} antialiased h-full`}>
        {children}
        <AppToaster />
      </body>
    </html>
  );
}
