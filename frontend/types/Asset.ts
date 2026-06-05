export interface Asset {
  id: number;
  name: string;
  value: string;
}

export interface AssetsResponse {
  assets: Asset[];
  total: number;
}

export interface AssetPayload {
  name: string;
  value: number;
}
