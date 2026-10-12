import Link from "next/link";

export default function TicketNotFound() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <p>チケットが見つかりません。</p>
      <Link href="/tickets" className="text-blue-600 underline">
        マイチケットへ
      </Link>
    </main>
  );
}
