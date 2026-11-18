import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Ticketours",
  description: "Administración de organizaciones de Ticketours",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es">
      <body>{children}</body>
    </html>
  );
}
