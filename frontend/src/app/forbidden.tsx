import Link from "next/link";

export default function Forbidden() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <p>この画面を表示する権限がありません。</p>
      <Link href="/" className="text-blue-600 underline">
        試合一覧へ戻る
      </Link>
    </main>
  );
}
