/** Laravel API resource wrapper. */
export interface Resource<T> {
  data: T
}

/** Laravel length-aware paginator, as serialized by resource collections. */
export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

/** Server-side table state shared by every list endpoint (see IndexRequest.php). */
export interface ListParams {
  page?: number
  per_page?: number
  /** Column name; prefix with "-" for descending. */
  sort?: string
  search?: string
  [filter: string]: string | number | boolean | undefined
}
