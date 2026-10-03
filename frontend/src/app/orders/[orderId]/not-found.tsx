import Link from "next/link";

export default function OrderNotFound() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <p>注文が見つかりません。</p>
      <Link href="/" className="text-blue-600 underline">
        試合一覧へ
      </Link>
    </main>
  );
}
